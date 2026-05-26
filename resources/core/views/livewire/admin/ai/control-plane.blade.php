<?php

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Livewire\ControlPlane;
use Illuminate\Support\Number;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var ControlPlane $this */
/** @var array<string, mixed>|null $runView */
/** @var LifecycleAction|null $selectedLifecycleAction */
/** @var array{label: string, url: string|null}|null $operationsBreadcrumb */
$controlPlaneContext = request()->only(['from', 'returnTo']);
?>
<div>
    <x-slot name="title">{{ __('Control Plane') }}</x-slot>

    <x-ui.page-header
        :title="__('Operator Control Plane')"
        :subtitle="__('Inspect runs end-to-end via the Wire Log card, review health signals, and manage AI lifecycle operations from one operator surface.')"
    >
        <x-slot name="actions">
            @if ($operationsBreadcrumb)
                @if ($operationsBreadcrumb['url'])
                    <a
                        href="{{ $operationsBreadcrumb['url'] }}"
                        wire:navigate
                        class="text-sm text-accent hover:underline"
                    >
                        {{ __('Back to :label', ['label' => $operationsBreadcrumb['label']]) }}
                    </a>
                @else
                    <span class="text-sm text-muted">{{ $operationsBreadcrumb['label'] }}</span>
                @endif
            @endif
        </x-slot>
        <x-slot name="help">
            <div class="space-y-3 text-sm text-muted">
                <p>{{ __('This page is for operator diagnostics. The Run Inspector card consolidates run details, transcript, and the Wire Log card (readable + raw) into a single drill-down surface for any run.') }}</p>
                <p>{{ __('Lifecycle milestones (run started, phase changes, terminal markers, tool denials, long heartbeat gaps) are surfaced as structural anchors next to the wire content rather than interleaved as fake transport rows.') }}</p>
            </div>
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs
        :tabs="[
            ['id' => 'inspector', 'label' => __('Run Inspector'), 'icon' => 'heroicon-o-magnifying-glass'],
            ['id' => 'health', 'label' => __('Health & Presence'), 'icon' => 'heroicon-o-heart'],
            ['id' => 'lifecycle', 'label' => __('Lifecycle Controls'), 'icon' => 'heroicon-o-arrow-path'],
        ]"
        :default="$activeTab"
        persistence="query"
        wire-action="setActiveTab"
    >
        <x-ui.tab id="inspector">
            <div class="space-y-section-gap">
                @include('livewire.admin.ai.control-plane.partials.recent-runs')

                @if ($runView)
                    <x-ui.card id="run-details-panel">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <h3 class="text-sm font-medium text-ink">{{ __('Run Details') }}</h3>
                            <x-ui.button as="a" href="#wire-log-panel" variant="ghost" size="sm">
                                {{ __('Wire Log') }}
                            </x-ui.button>
                        </div>
                        @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $runView['inspection']])
                    </x-ui.card>

                    @include('livewire.admin.ai.control-plane.partials.activity-transcript-card', [
                        'transcript' => $runView['transcript'],
                        'triggeringPrompt' => $runView['triggering_prompt'],
                    ])

                    <x-ui.card id="wire-log-panel">
                        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                            <h3 class="text-sm font-medium text-ink">{{ __('Wire Log') }}</h3>
                            <p class="text-xs text-muted tabular-nums">
                                {{ __('Total footprint: :size', ['size' => Number::fileSize($wireLogDiskUsageBytes)]) }}
                            </p>
                        </div>
                        @include('livewire.admin.ai.control-plane.partials.wire-log', [
                            'entries' => $runView['wire_log_entries'],
                            'readable' => $runView['wire_log_readable'],
                            'summary' => $runView['wire_log_summary'],
                            'wireLoggingEnabled' => $runView['wire_logging_enabled'],
                            'runId' => $inspectRunId,
                            'lifecycleMilestones' => $runView['lifecycle_milestones'] ?? [],
                            'lifecycleRail' => $runView['lifecycle_rail'] ?? null,
                        ])
                    </x-ui.card>
                @endif
            </div>
        </x-ui.tab>

        <x-ui.tab id="health">
            <div class="space-y-section-gap">
                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-ink">{{ __('Run Queue Health') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Queue saturation, stale runs, and recent failures are surfaced separately so operators can distinguish load from breakage.') }}</p>
                        </div>
                        <x-ui.button wire:click="refreshHealthSnapshots" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    @if ($runHealthCounts)
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Active Runs') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight text-ink">{{ $runHealthCounts['queued'] + $runHealthCounts['booting'] + $runHealthCounts['running'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Stale Runs') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight {{ ($runHealthCounts['stale_queued'] + $runHealthCounts['stale_running']) > 0 ? 'text-danger' : 'text-ink' }}">{{ $runHealthCounts['stale_queued'] + $runHealthCounts['stale_running'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Completed Last Hour') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight text-success">{{ $runHealthCounts['completed_last_hour'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Failed Last Hour') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight {{ $runHealthCounts['failed_last_hour'] > 0 ? 'text-warning' : 'text-ink' }}">{{ $runHealthCounts['failed_last_hour'] }}</p>
                            </div>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-ink">{{ __('Provider Health') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Provider readiness, health, and presence are shown as separate states rather than one blended badge.') }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @forelse ($providerSnapshots as $snapshot)
                            <x-ui.card class="border border-border-default shadow-none">
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-sm font-medium text-ink">{{ $snapshot['target_id'] }}</p>
                                        <p class="mt-1 text-xs text-muted">{{ $snapshot['explanation'] }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.badge :variant="$snapshot['readiness_color']">{{ $snapshot['readiness_label'] }}</x-ui.badge>
                                        <x-ui.badge :variant="$snapshot['health_color']">{{ $snapshot['health_label'] }}</x-ui.badge>
                                        <x-ui.badge :variant="$snapshot['presence_color']">{{ $snapshot['presence_label'] }}</x-ui.badge>
                                    </div>
                                </div>
                            </x-ui.card>
                        @empty
                            <x-ui.alert variant="info">{{ __('No active providers are configured.') }}</x-ui.alert>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                        <div class="space-y-3">
                            <x-ui.select id="health-agent-id" wire:model="healthAgentId" :label="__('Agent')">
                                @foreach ($agentOptions as $agent)
                                    <option value="{{ $agent['id'] }}">{{ $agent['label'] }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.button wire:click="loadAgentSnapshot" variant="secondary" size="sm">
                                {{ __('Load Agent Health') }}
                            </x-ui.button>
                        </div>

                        @if ($agentSnapshot)
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Readiness') }}</p>
                                    <div class="mt-2"><x-ui.badge :variant="$agentSnapshot['readiness_color']">{{ $agentSnapshot['readiness_label'] }}</x-ui.badge></div>
                                </div>
                                <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Health') }}</p>
                                    <div class="mt-2"><x-ui.badge :variant="$agentSnapshot['health_color']">{{ $agentSnapshot['health_label'] }}</x-ui.badge></div>
                                </div>
                                <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Presence') }}</p>
                                    <div class="mt-2"><x-ui.badge :variant="$agentSnapshot['presence_color']">{{ $agentSnapshot['presence_label'] }}</x-ui.badge></div>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-ink">{{ __('Tool Health') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Tool readiness, verification health, and presence are kept distinct for easier triage.') }}</p>
                        </div>
                    </div>

                    <x-ui.table container="plain" :caption="__('Tool health')">

                        <x-slot name="head">
                                <tr>
                                    <x-ui.th>{{ __('Tool') }}</x-ui.th>
                                    <x-ui.th>{{ __('Readiness') }}</x-ui.th>
                                    <x-ui.th>{{ __('Health') }}</x-ui.th>
                                    <x-ui.th>{{ __('Presence') }}</x-ui.th>
                                    <x-ui.th>{{ __('Explanation') }}</x-ui.th>
                                </tr>
                            </x-slot>

                                @forelse ($toolSnapshots as $snapshot)
                                    <tr wire:key="tool-snapshot-{{ $snapshot['target_id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y font-medium text-ink">{{ $snapshot['target_id'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$snapshot['readiness_color']">{{ $snapshot['readiness_label'] }}</x-ui.badge></td>
                                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$snapshot['health_color']">{{ $snapshot['health_label'] }}</x-ui.badge></td>
                                        <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$snapshot['presence_color']">{{ $snapshot['presence_label'] }}</x-ui.badge></td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $snapshot['explanation'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No tool snapshots are available.') }}</td>
                                    </tr>
                                @endforelse


                    </x-ui.table>
                </x-ui.card>
            </div>
        </x-ui.tab>

        <x-ui.tab id="lifecycle">
            <div class="space-y-section-gap">
                <x-ui.card>
                    <div class="space-y-4">
                        <div class="grid gap-3 lg:grid-cols-2">
                            <x-ui.select id="lifecycle-action" wire:model.live="lifecycleAction" :label="__('Action')">
                                <option value="">{{ __('Select action') }}</option>
                                @foreach (LifecycleAction::cases() as $action)
                                    <option value="{{ $action->value }}">{{ __($action->label()) }}</option>
                                @endforeach
                            </x-ui.select>

                            @if (in_array($lifecycleAction, ['compact_memory', 'prune_sessions'], true))
                                <x-ui.select id="lifecycle-employee-id" wire:model="lifecycleEmployeeId" :label="__('Agent')">
                                    @foreach ($agentOptions as $agent)
                                        <option value="{{ $agent['id'] }}">{{ $agent['label'] }}</option>
                                    @endforeach
                                </x-ui.select>
                            @endif

                            @if (in_array($lifecycleAction, ['prune_sessions', 'prune_wire_logs'], true))
                                <x-ui.input
                                    id="lifecycle-retention-days"
                                    wire:model="lifecycleRetentionDays"
                                    type="number"
                                    :label="__('Retention Days')"
                                />
                            @endif

                            @if ($lifecycleAction === 'sweep_operations')
                                <x-ui.input
                                    id="lifecycle-stale-minutes"
                                    wire:model="lifecycleStaleMinutes"
                                    type="number"
                                    :label="__('Stale Minutes')"
                                />
                            @endif

                            @if ($lifecycleAction === 'prune_artifacts')
                                <x-ui.input
                                    id="lifecycle-session-id"
                                    wire:model="lifecycleSessionId"
                                    :label="__('Session ID')"
                                    :placeholder="__('sess_...')"
                                />
                            @endif
                        </div>

                        @if ($selectedLifecycleAction)
                            <div class="rounded-2xl border border-border-default bg-surface-subtle p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action Description') }}</p>
                                <p class="mt-2 text-sm text-ink">{{ $selectedLifecycleAction->description() }}</p>
                            </div>
                        @endif

                        <div class="flex gap-2">
                            <x-ui.button wire:click="previewLifecycleAction" variant="secondary" size="sm">
                                {{ __('Preview') }}
                            </x-ui.button>
                            <x-ui.button
                                wire:click="executeLifecycleAction"
                                wire:confirm="{{ __('This will execute the selected lifecycle action. Continue?') }}"
                                variant="primary"
                                size="sm"
                            >
                                {{ __('Execute') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                @if ($lifecycleError)
                    <x-ui.alert variant="warning">{{ $lifecycleError }}</x-ui.alert>
                @endif

                @if ($lifecyclePreview)
                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Preview') }}</h3>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</p>
                                <p class="mt-1 text-sm text-ink">{{ $lifecyclePreview['action_label'] }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Affected') }}</p>
                                <p class="mt-1 text-sm text-ink">{{ number_format($lifecyclePreview['affected_count']) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Destructive') }}</p>
                                <div class="mt-1">
                                    <x-ui.badge :variant="$lifecyclePreview['is_destructive'] ? 'danger' : 'success'">
                                        {{ $lifecyclePreview['is_destructive'] ? __('Yes') : __('No') }}
                                    </x-ui.badge>
                                </div>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Generated At') }}</p>
                                <p class="mt-1 text-sm text-ink tabular-nums">{{ $lifecyclePreview['generated_at'] }}</p>
                            </div>
                        </div>
                        <div class="mt-4 space-y-2">
                            @foreach ($lifecyclePreview['affected_summary'] as $summary)
                                <div class="rounded-2xl border border-border-default bg-surface-subtle p-3 text-sm text-ink">{{ $summary }}</div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                @if ($lifecycleResult)
                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Last Execution') }}</h3>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Request ID') }}</p>
                                <p class="mt-1 font-mono text-xs text-ink">{{ $lifecycleResult['request_id'] }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</p>
                                <p class="mt-1 text-sm text-ink">{{ $lifecycleResult['action_label'] }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</p>
                                <div class="mt-1"><x-ui.badge :variant="$lifecycleResult['status_color']">{{ $lifecycleResult['status_label'] }}</x-ui.badge></div>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Finished At') }}</p>
                                <p class="mt-1 text-sm text-ink tabular-nums">{{ $lifecycleResult['finished_at'] ?? '---' }}</p>
                            </div>
                        </div>

                        @if (! empty($lifecycleResult['result']))
                            <pre class="mt-4 overflow-x-auto rounded-2xl bg-surface-subtle p-3 text-[11px] text-muted">{{ json_encode($lifecycleResult['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </x-ui.card>
                @endif

                <x-ui.card>
                    <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Recent Lifecycle Requests') }}</h3>
                    <div class="space-y-3">
                        @forelse ($recentLifecycleRequests as $request)
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-ink">{{ $request['action_label'] }}</p>
                                        <p class="mt-1 text-xs font-mono text-muted">{{ $request['request_id'] }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <x-ui.badge :variant="$request['status_color']">{{ $request['status_label'] }}</x-ui.badge>
                                        <span class="text-xs text-muted tabular-nums">{{ $request['created_at'] }}</span>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <x-ui.alert variant="info">{{ __('No lifecycle actions have been recorded yet.') }}</x-ui.alert>
                        @endforelse
                    </div>
                </x-ui.card>
            </div>
        </x-ui.tab>
    </x-ui.tabs>
</div>
