<?php

namespace App\Modules\Core\AI\Services\Orchestration;

use App\Modules\Core\AI\Contracts\Orchestration\RuntimeHook;
use App\Modules\Core\AI\DTO\Orchestration\HookPayload;
use App\Modules\Core\AI\DTO\Orchestration\HookResult;
use App\Modules\Core\AI\Enums\HookStage;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\Runtime\RuntimeSessionContext;

/**
 * Injects auto-selected skill pack prompt bodies at PreContextBuild.
 */
class SkillContextInjectionHook implements RuntimeHook
{
    public function __construct(
        private readonly SkillSelectionService $selection,
        private readonly SkillPackRegistry $registry,
        private readonly PageContextHolder $pageContextHolder,
        private readonly RuntimeSessionContext $sessionContext,
    ) {}

    public function stage(): HookStage
    {
        return HookStage::PreContextBuild;
    }

    public function priority(): int
    {
        return 60;
    }

    public function identifier(): string
    {
        return 'skill.context-injection';
    }

    public function execute(HookPayload $payload): HookResult
    {
        $userMessage = $this->latestUserMessage($payload);
        $selectedIds = $this->selection->selectForTurn(
            $payload->employeeId,
            $userMessage,
            $this->pageContextHolder->getContext(),
        );

        if ($selectedIds === []) {
            return HookResult::noop();
        }

        $sections = [];
        $resolved = [];

        foreach ($selectedIds as $packId) {
            $manifest = $this->registry->find($packId);

            if ($manifest === null || ! $manifest->isAvailable()) {
                continue;
            }

            $assembled = [];

            foreach ($manifest->promptResources as $resource) {
                $assembled[] = $resource->content;
            }

            if ($assembled === []) {
                continue;
            }

            $sections[] = implode("\n\n", $assembled);
            $resolved[] = $packId;
        }

        if ($sections === []) {
            return HookResult::noop();
        }

        $this->sessionContext->remember('resolved_skill_pack_ids', $resolved);

        return new HookResult(
            handled: true,
            promptSections: $sections,
            metadata: [
                'resolved_skill_pack_ids' => $resolved,
            ],
        );
    }

    private function latestUserMessage(HookPayload $payload): ?string
    {
        $fromPayload = $payload->data['user_message'] ?? null;

        if (is_string($fromPayload) && trim($fromPayload) !== '') {
            return $fromPayload;
        }

        $fromSession = $this->sessionContext->recall('latest_user_message');

        return is_string($fromSession) && trim($fromSession) !== '' ? $fromSession : null;
    }
}
