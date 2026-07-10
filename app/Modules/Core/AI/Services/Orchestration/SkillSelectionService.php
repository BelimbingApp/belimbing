<?php

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Selects which skill packs should inject full guidance for a turn.
 *
 * Progressive disclosure: the catalog always lists packs; this service
 * chooses a small set of bodies to inject from page suggestions and
 * message matches. Explicit load_skill remains available mid-turn.
 */
class SkillSelectionService
{
    public const MAX_AUTO_SELECTED = 2;

    public function __construct(
        private readonly SkillPackRegistry $registry,
        private readonly OrchestrationPolicyService $policy,
        private readonly PageContextHolder $pageContextHolder,
    ) {}

    /**
     * @return list<string> Ordered pack ids to inject
     */
    public function selectForTurn(int $employeeId, ?string $userMessage = null, ?PageContext $pageContext = null): array
    {
        $pageContext ??= $this->pageContextHolder->getContext();
        $selected = [];

        foreach ($this->pageSuggestedIds($pageContext) as $packId) {
            $manifest = $this->resolvePack($packId, $employeeId);

            if ($manifest === null || in_array($manifest->id, $selected, true)) {
                continue;
            }

            $selected[] = $manifest->id;

            if (count($selected) >= self::MAX_AUTO_SELECTED) {
                return $selected;
            }
        }

        foreach ($this->messageMatchedIds($employeeId, $userMessage) as $packId) {
            if (in_array($packId, $selected, true)) {
                continue;
            }

            $selected[] = $packId;

            if (count($selected) >= self::MAX_AUTO_SELECTED) {
                break;
            }
        }

        return $selected;
    }

    /**
     * Resolve a pack id or trailing slug to a registered available pack.
     */
    public function resolvePack(string $packIdOrSlug, int $employeeId): ?SkillPackManifest
    {
        $needle = trim($packIdOrSlug);

        if ($needle === '') {
            return null;
        }

        $direct = $this->registry->find($needle);

        if ($direct !== null && $this->policy->isSkillPackApplicable($direct, $employeeId)) {
            return $direct;
        }

        $normalized = strtolower($needle);
        $slugNeedle = $this->trailingSlug($normalized);

        foreach ($this->registry->all() as $manifest) {
            if (! $this->policy->isSkillPackApplicable($manifest, $employeeId)) {
                continue;
            }

            if (strtolower($manifest->id) === $normalized) {
                return $manifest;
            }

            if ($this->trailingSlug($manifest->id) === $slugNeedle) {
                return $manifest;
            }

            if (strtolower($manifest->name) === $normalized) {
                return $manifest;
            }
        }

        return null;
    }

    /**
     * Compact catalog entries for Lara runtime context (no skill bodies).
     *
     * Filtered by the same applicability policy as resolvePack() so the
     * catalog never advertises a skill load_skill would refuse.
     *
     * @return list<array{id: string, name: string, description: string, owner: string|null, path: string|null}>
     */
    public function catalogEntries(?int $employeeId = null): array
    {
        $employeeId ??= Employee::LARA_ID;
        $entries = [];

        foreach ($this->registry->all() as $manifest) {
            if (! $this->policy->isSkillPackApplicable($manifest, $employeeId)) {
                continue;
            }

            $entries[] = [
                'id' => $manifest->id,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'owner' => $manifest->owner,
                'path' => $manifest->references[0]->path ?? null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function pageSuggestedIds(?PageContext $pageContext): array
    {
        if ($pageContext === null) {
            return [];
        }

        return array_values(array_filter(
            $pageContext->suggestedSkills,
            fn (mixed $id): bool => is_string($id) && trim($id) !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function messageMatchedIds(int $employeeId, ?string $userMessage): array
    {
        if ($userMessage === null || trim($userMessage) === '') {
            return [];
        }

        $haystack = mb_strtolower($userMessage);
        $scored = [];

        foreach ($this->registry->all() as $manifest) {
            if (! $this->policy->isSkillPackApplicable($manifest, $employeeId)) {
                continue;
            }

            $score = $this->matchScore($manifest, $haystack);

            if ($score > 0) {
                $scored[] = ['id' => $manifest->id, 'score' => $score];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(fn (array $row): string => $row['id'], $scored);
    }

    private function matchScore(SkillPackManifest $manifest, string $haystack): int
    {
        $score = 0;
        $id = strtolower($manifest->id);
        $slug = $this->trailingSlug($id);
        $name = mb_strtolower($manifest->name);
        $description = mb_strtolower($manifest->description);

        if (str_contains($haystack, $id)) {
            $score += 100;
        }

        if ($slug !== '' && str_contains($haystack, $slug)) {
            $score += 80;
        }

        if ($name !== '' && str_contains($haystack, $name)) {
            $score += 60;
        }

        foreach ($this->significantTokens($slug.' '.$name.' '.$description) as $token) {
            if (str_contains($haystack, $token)) {
                $score += 10;
            }
        }

        // Require a skill-intent cue so ordinary chat does not auto-load packs.
        if ($score > 0 && ! $this->mentionsSkillIntent($haystack)) {
            return 0;
        }

        return $score;
    }

    private function mentionsSkillIntent(string $haystack): bool
    {
        // Noun cues only — verb phrases like "use the" match too much
        // ordinary chat and auto-inject bodies on false positives.
        foreach (['skill', 'procedure', 'playbook'] as $cue) {
            if (str_contains($haystack, $cue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'from', 'this', 'that', 'when', 'use', 'skill', 'module', 'kiat', 'extension', 'core'];

        $tokens = [];

        foreach ($parts as $part) {
            if (strlen($part) < 4 || in_array($part, $stop, true)) {
                continue;
            }

            $tokens[$part] = true;
        }

        return array_keys($tokens);
    }

    private function trailingSlug(string $id): string
    {
        $parts = explode('.', $id);

        return $parts[array_key_last($parts)] ?? $id;
    }
}
