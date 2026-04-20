<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\Foundation\Providers\ProviderRegistry;
use App\Modules\Core\AI\Models\AiProvider;

class LaraContextProvider
{
    public function __construct(
        private readonly KnowledgeNavigator $knowledgeNavigator,
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
            'modules' => $this->installedModules(),
            'providers' => $this->configuredProviders($companyId),
            'knowledge' => $this->knowledgeContext($query),
        ];
    }

    /**
     * Return discovered application module names.
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

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
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
        $id = auth()->user()?->employee?->company_id;

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
