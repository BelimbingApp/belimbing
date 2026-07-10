<?php

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\AI\Services\ShellCommandRunner;
use App\Base\Foundation\Contracts\CompanyScoped;
use App\Base\Foundation\Providers\ProviderRegistry;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\Orchestration\SkillSelectionService;
use Illuminate\Contracts\Auth\Authenticatable;

class LaraContextProvider
{
    public function __construct(
        private readonly KnowledgeNavigator $knowledgeNavigator,
        private readonly ?ShellCommandRunner $shellRunner = null,
        private readonly ?SkillSelectionService $skillSelection = null,
    ) {}

    /**
     * Build runtime context for Lara based on the authenticated user's scope.
     *
     * @return array<string, mixed>
     */
    public function contextForCurrentUser(?string $query = null): array
    {
        $companyId = $this->authenticatedCompanyId();

        return [
            'app' => [
                'name' => (string) config('app.name'),
                'env' => (string) config('app.env'),
            ],
            'actor' => [
                'user_id' => $this->authenticatedUserId(),
                'company_id' => $companyId,
            ],
            'repository' => $this->repositoryContext(),
            'shell' => $this->shellContext(),
            'modules' => $this->installedModules(),
            'skills' => $this->skillsContext(),
            'providers' => $this->configuredProviders($companyId),
            'knowledge' => $this->knowledgeContext($query),
        ];
    }

    /**
     * Repository context is the in-product equivalent of launching a CLI coding
     * agent from the project root.
     *
     * @return array{default_surface: string, path_convention: string, surfaces: array{core: array{root: string}, extensions: array{root_patterns: list<string>}}}
     */
    private function repositoryContext(): array
    {
        return [
            'default_surface' => 'core',
            'path_convention' => 'Repository tool file paths are relative to target_surface. The core surface is the project root.',
            'coding_loop' => [
                'phases' => ['localize_source', 'focused_read', 'edit_plan', 'patch', 'verify', 'summarize'],
                'source_localization' => 'Before editing, identify the source-of-truth file or small candidate set. If the user named an exact file, localize to that file first.',
                'edit_plan' => 'Before patching, name target files and why each is the source of truth. Prefer the lowest sufficient blast radius.',
                'post_edit_validation' => 'After editing, inspect the resulting diff or changed-file summary, run the narrowest useful verification, and report any unverified risk.',
            ],
            'surfaces' => [
                'core' => [
                    'root' => '.',
                ],
                'extensions' => [
                    'root_patterns' => [
                        'extensions/<slug>',
                        'extensions/custom/<slug>',
                        'extensions/vendor/<slug>',
                    ],
                ],
            ],
        ];
    }

    /**
     * Return the active shell backend name and label, or null if unavailable.
     *
     * @return array{backend: string, label: string}|null
     */
    private function shellContext(): ?array
    {
        $runner = $this->shellRunner ?? app(ShellCommandRunner::class);

        try {
            return [
                'backend' => $runner->backendName(),
                'label' => $runner->backendLabel(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Return discovered application and extension module identities.
     *
     * @return list<string>
     */
    private function installedModules(): array
    {
        $modules = [];

        foreach (ProviderRegistry::discoverModuleProviders() as $providerClass) {
            $parts = explode('\\', $providerClass);

            if (count($parts) < 5 || $parts[0] !== 'App' || $parts[1] !== 'Modules') {
                continue;
            }

            $modules[] = $parts[3];
        }

        foreach (ProviderRegistry::discoverExtensionProviders() as $providerClass) {
            $parts = explode('\\', $providerClass);

            if (count($parts) < 4 || $parts[0] !== 'Extensions') {
                continue;
            }

            $owner = str($parts[1])->kebab()->toString();
            $module = str($parts[2])->kebab()->toString();
            $modules[] = $owner.'/'.$module;
        }

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    /**
     * Compact skill catalog for progressive disclosure.
     *
     * @return array{catalog: list<array{id: string, name: string, description: string, owner: string|null, path: string|null}>, usage: string}
     */
    private function skillsContext(): array
    {
        $selection = $this->skillSelection ?? app(SkillSelectionService::class);

        return [
            'catalog' => $selection->catalogEntries(),
            'usage' => 'Catalog lists available skills. Call load_skill with a catalog id before following a procedure. Prefer current_page suggested_skills when present.',
        ];
    }

    /**
     * Return active providers for the current company.
     *
     * @return list<array{name: string, display_name: string, base_url: string}>
     */
    private function configuredProviders(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return AiProvider::getConfiguredForCompany($companyId)
            ->map(fn (AiProvider $provider): array => [
                'name' => (string) $provider->name,
                'display_name' => (string) $provider->display_name,
                'base_url' => (string) $provider->base_url,
            ])
            ->values()
            ->all();
    }

    private function authenticatedCompanyId(): ?int
    {
        $user = auth()->user();

        if ($user instanceof CompanyScoped) {
            return $user->getCompanyId();
        }

        if (! $user instanceof Authenticatable || ! method_exists($user, 'getAttribute')) {
            return null;
        }

        $id = $user->getAttribute('company_id');

        return is_int($id) ? $id : null;
    }

    private function authenticatedUserId(): ?int
    {
        $id = auth()->id();

        return is_int($id) ? $id : null;
    }

    /**
     * @return array{commands: array{go: string, models: string, guide: string, delegate: string}, query_references: list<array{title: string, path: string, summary: string}>}
     */
    private function knowledgeContext(?string $query): array
    {
        $context = [
            'commands' => [
                'go' => '/go <target>',
                'models' => '/models <filter>',
                'guide' => '/guide <topic>',
                'delegate' => '/delegate <task>',
            ],
        ];

        if (is_string($query)) {
            $references = $this->knowledgeNavigator->search($query);

            if ($references !== []) {
                $context['query_references'] = $references;
            }
        }

        return $context;
    }
}
