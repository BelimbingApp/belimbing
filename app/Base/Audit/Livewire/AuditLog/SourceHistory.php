<?php

namespace App\Base\Audit\Livewire\AuditLog;

use App\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithSourceHistory;
use App\Base\Audit\Livewire\AuditLog\Concerns\InteractsWithTraceTimeline;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SourceHistory extends Component
{
    use InteractsWithSourceHistory;
    use InteractsWithTraceTimeline {
        openTrace as private openAuthorizedTrace;
    }

    public string $title = '';

    /** @var list<array{name: string, id: int|string, identifier?: string|null}> */
    public array $subjects = [];

    public ?string $auditableType = null;

    public int|string|null $auditableId = null;

    public string $allUrl = '';

    public string $buttonLabel = '';

    public bool $iconOnly = false;

    public ?string $subjectLabel = null;

    public string $sourceCapability = '';

    /**
     * When false, only {@see $sourceCapability} is required for local History.
     * Trace timelines and the full Audit Log URL still require the admin list
     * capability so maintainers never inherit the broad audit surface.
     */
    public bool $requireAuditListCapability = true;

    public function open(): void
    {
        if (! $this->canViewAuditHistory()) {
            return;
        }

        $this->openSourceHistory(
            title: $this->title !== '' ? $this->title : __('History'),
            subjects: $this->subjects,
            auditableType: $this->auditableType,
            auditableId: $this->auditableId,
            allUrl: $this->localHistoryAllUrl(),
            subjectLabel: $this->subjectLabel,
        );
    }

    public function openTrace(string $traceId): void
    {
        // Trace drawers can surface sibling rows in the same request; keep them
        // on the auditor path even when local History is source-capability-only.
        if (! $this->canViewAuditHistory() || ! $this->canOpenAuditTraces()) {
            return;
        }

        $this->openAuthorizedTrace($traceId);
    }

    public function render(): View
    {
        return view('livewire.admin.audit.source-history', [
            'canViewAuditHistory' => $this->canViewAuditHistory(),
            'buttonLabelText' => $this->buttonLabel !== '' ? $this->buttonLabel : __('History'),
        ]);
    }

    private function canViewAuditHistory(): bool
    {
        $authUser = auth()->user();

        if ($authUser === null || $this->sourceCapability === '') {
            return false;
        }

        $authorization = app(AuthorizationService::class);
        $actor = Actor::forUser($authUser);

        if (! $authorization->can($actor, $this->sourceCapability)->allowed) {
            return false;
        }

        return ! $this->requireAuditListCapability
            || $authorization->can($actor, 'admin.audit.log.list')->allowed;
    }

    private function canOpenAuditTraces(): bool
    {
        $authUser = auth()->user();

        if ($authUser === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($authUser), 'admin.audit.log.list')
            ->allowed;
    }

    private function localHistoryAllUrl(): ?string
    {
        if (! $this->requireAuditListCapability || $this->allUrl === '') {
            return null;
        }

        return $this->allUrl;
    }
}
