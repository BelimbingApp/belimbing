<?php

namespace App\Base\Audit\Livewire\AuditLog\Concerns;

use App\Base\Audit\Services\AuditSourceHistory;

trait InteractsWithSourceHistory
{
    public bool $sourceHistoryDrawerOpen = false;

    public string $sourceHistoryTitle = '';

    public string $sourceHistoryAllUrl = '';

    /** @var array<string, mixed> */
    public array $sourceHistory = [];

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    protected function openSourceHistory(
        string $title,
        array $subjects,
        ?string $auditableType = null,
        int|string|null $auditableId = null,
        ?string $allUrl = null,
    ): void {
        $this->sourceHistoryTitle = $title;
        $this->sourceHistoryAllUrl = $allUrl ?? '';
        $this->sourceHistory = app(AuditSourceHistory::class)->forRecord(
            subjects: $subjects,
            auditableType: $auditableType,
            auditableId: $auditableId,
        );
        $this->sourceHistoryDrawerOpen = true;
    }

    public function closeSourceHistory(): void
    {
        $this->sourceHistoryDrawerOpen = false;
        $this->sourceHistoryTitle = '';
        $this->sourceHistoryAllUrl = '';
        $this->sourceHistory = [];
    }
}
