<?php

namespace App\Base\Audit\Livewire\AuditLog\Concerns;

use App\Base\Audit\Services\AuditSourceHistory;

trait InteractsWithSourceHistory
{
    private const SOURCE_HISTORY_DEFAULT_LIMIT = 50;

    private const SOURCE_HISTORY_LIMIT_INCREMENT = 50;

    private const SOURCE_HISTORY_SORTABLE = ['occurred_at', 'actor', 'event', 'trace_id'];

    public bool $sourceHistoryDrawerOpen = false;

    public string $sourceHistoryTitle = '';

    public string $sourceHistorySubjectLabel = '';

    public string $sourceHistoryAllUrl = '';

    public string $sourceHistorySearch = '';

    public string $sourceHistorySortBy = 'occurred_at';

    public string $sourceHistorySortDir = 'desc';

    public int $sourceHistoryLimit = self::SOURCE_HISTORY_DEFAULT_LIMIT;

    /** @var list<array{name: string, id: int|string, identifier?: string|null}> */
    public array $sourceHistorySubjects = [];

    public ?string $sourceHistoryAuditableType = null;

    public int|string|null $sourceHistoryAuditableId = null;

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
        $this->sourceHistorySubjectLabel = $this->sourceHistorySubjectLabel($subjects);
        $this->sourceHistoryAllUrl = $allUrl ?? '';
        $this->sourceHistorySearch = '';
        $this->sourceHistorySortBy = 'occurred_at';
        $this->sourceHistorySortDir = 'desc';
        $this->sourceHistoryLimit = self::SOURCE_HISTORY_DEFAULT_LIMIT;
        $this->sourceHistorySubjects = $subjects;
        $this->sourceHistoryAuditableType = $auditableType;
        $this->sourceHistoryAuditableId = $auditableId;
        $this->refreshSourceHistory();
        $this->sourceHistoryDrawerOpen = true;
    }

    public function updatedSourceHistorySearch(): void
    {
        $this->sourceHistoryLimit = self::SOURCE_HISTORY_DEFAULT_LIMIT;
        $this->refreshSourceHistory();
    }

    public function clearSourceHistorySearch(): void
    {
        $this->sourceHistorySearch = '';
        $this->sourceHistoryLimit = self::SOURCE_HISTORY_DEFAULT_LIMIT;
        $this->refreshSourceHistory();
    }

    public function sortSourceHistory(string $column): void
    {
        if (! in_array($column, self::SOURCE_HISTORY_SORTABLE, true)) {
            return;
        }

        if ($this->sourceHistorySortBy === $column) {
            $this->sourceHistorySortDir = $this->sourceHistorySortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sourceHistorySortBy = $column;
            $this->sourceHistorySortDir = $column === 'occurred_at' ? 'desc' : 'asc';
        }

        $this->refreshSourceHistory();
    }

    public function loadMoreSourceHistory(): void
    {
        $this->sourceHistoryLimit += self::SOURCE_HISTORY_LIMIT_INCREMENT;
        $this->refreshSourceHistory();
    }

    public function closeSourceHistory(): void
    {
        $this->sourceHistoryDrawerOpen = false;
        $this->sourceHistoryTitle = '';
        $this->sourceHistorySubjectLabel = '';
        $this->sourceHistoryAllUrl = '';
        $this->sourceHistorySearch = '';
        $this->sourceHistorySortBy = 'occurred_at';
        $this->sourceHistorySortDir = 'desc';
        $this->sourceHistoryLimit = self::SOURCE_HISTORY_DEFAULT_LIMIT;
        $this->sourceHistorySubjects = [];
        $this->sourceHistoryAuditableType = null;
        $this->sourceHistoryAuditableId = null;
        $this->sourceHistory = [];
    }

    private function refreshSourceHistory(): void
    {
        $this->sourceHistory = app(AuditSourceHistory::class)->forRecord(
            subjects: $this->sourceHistorySubjects,
            auditableType: $this->sourceHistoryAuditableType,
            auditableId: $this->sourceHistoryAuditableId,
            limit: $this->sourceHistoryLimit,
            search: $this->sourceHistorySearch,
            sortBy: $this->sourceHistorySortBy,
            sortDir: $this->sourceHistorySortDir,
        );
    }

    /**
     * @param  list<array{name: string, id: int|string, identifier?: string|null}>  $subjects
     */
    private function sourceHistorySubjectLabel(array $subjects): string
    {
        $subject = $subjects[0] ?? null;

        if (! is_array($subject) || ! isset($subject['name'], $subject['id'])) {
            return '';
        }

        $name = strtolower(trim((string) $subject['name']));
        $id = trim((string) $subject['id']);

        if ($name === '' || $id === '') {
            return '';
        }

        return $name.'#'.$id;
    }
}
