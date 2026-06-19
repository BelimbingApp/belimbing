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

    public string $sourceCapability = '';

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
            allUrl: $this->allUrl !== '' ? $this->allUrl : null,
        );
    }

    public function openTrace(string $traceId): void
    {
        if (! $this->canViewAuditHistory()) {
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

        if ($authUser === null) {
            return false;
        }

        if ($this->sourceCapability === '') {
            return false;
        }

        $authorization = app(AuthorizationService::class);
        $actor = Actor::forUser($authUser);

        if (! $authorization->can($actor, 'admin.audit.log.list')->allowed) {
            return false;
        }

        return $authorization->can($actor, $this->sourceCapability)->allowed;
    }
}
