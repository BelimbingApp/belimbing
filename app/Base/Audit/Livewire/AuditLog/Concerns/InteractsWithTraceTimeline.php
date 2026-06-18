<?php

namespace App\Base\Audit\Livewire\AuditLog\Concerns;

use App\Base\Audit\Services\AuditLogPresenter;
use App\Base\Audit\Services\AuditTraceTimeline;

trait InteractsWithTraceTimeline
{
    public bool $traceDrawerOpen = false;

    public string $selectedTraceId = '';

    /** @var array<string, mixed> */
    public array $traceTimeline = [];

    public function openTrace(string $traceId): void
    {
        $normalized = app(AuditLogPresenter::class)->normalizeTrace($traceId);

        if ($normalized === '') {
            return;
        }

        $this->selectedTraceId = $normalized;
        $this->traceTimeline = app(AuditTraceTimeline::class)->forTrace($normalized);
        $this->traceDrawerOpen = true;
    }

    public function closeTrace(): void
    {
        $this->traceDrawerOpen = false;
        $this->selectedTraceId = '';
        $this->traceTimeline = [];
    }
}
