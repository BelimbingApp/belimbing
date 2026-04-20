<?php

use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Livewire\ControlPlane;
use Illuminate\Support\Str;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var ControlPlane $this */
/** @var array<string, mixed>|null $runView */
/** @var array<string, mixed>|null $turnView */
/** @var LifecycleAction|null $selectedLifecycleAction */
/** @var array{label: string, url: string|null}|null $operationsBreadcrumb */
$controlPlaneContext = request()->only(['from', 'returnTo']);
?>
<div>
    <x-slot name="title">{{ __('Control Plane') }}</x-slot>

    <x-ui.page-header
        :title="__('Operator Control Plane')"
        :subtitle="__('Inspect recent runs and turns, review health signals, and manage AI lifecycle operations from one operator surface.')"
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
                <p>{{ __('This page is for operator diagnostics. It exposes recent runtime activity, per-run transcripts, turn timelines, provider and tool health, and destructive lifecycle actions.') }}</p>
                <p>{{ __('Run inspection now centers on direct run IDs and recent activity instead of employee-scoped session lookups, so cross-agent admin drill-down remains accurate.') }}</p>
            </div>
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs
        :tabs="[
            ['id' => 'inspector', 'label' => __('Run Inspector'), 'icon' => 'heroicon-o-magnifying-glass'],
            ['id' => 'turns', 'label' => __('Turn Inspector'), 'icon' => 'heroicon-o-chat-bubble-left-right'],
            ['id' => 'health', 'label' => __('Health & Presence'), 'icon' => 'heroicon-o-heart'],
            ['id' => 'lifecycle', 'label' => __('Lifecycle Controls'), 'icon' => 'heroicon-o-arrow-path'],
        ]"
        :default="$activeTab"
    >
        <x-ui.tab id="inspector">
            <div class="space-y-section-gap">
                <x-ui.card>
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <div class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <x-ui.input
                                    id="inspect-run-id"
                                    wire:model="inspectRunId"
                                    :label="__('Run ID')"
                                    :placeholder="__('run_abc123')"
                                />
                                <div class="flex items-end">
                                    <x-ui.button wire:click="inspectRun" variant="primary" size="sm">
                                        {{ __('Inspect Run') }}
                                    </x-ui.button>
                                </div>
                            </div>

                            @if ($inspectionError)
                                <x-ui.alert variant="warning">{{ $inspectionError }}</x-ui.alert>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-border-default bg-surface-subtle p-card-inner">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Wire Log Footprint') }}</p>
                            <p class="mt-2 text-2xl font-medium tracking-tight text-ink">{{ number_format($wireLogDiskUsageBytes / 1024, 1) }} KB</p>
                            <p class="mt-1 text-xs text-muted">{{ __('Retained raw transport and tool payloads across all employees.') }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-ink">{{ __('Recent Runs') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Newest runs first. Inspecting a row loads transcript, prompt, and wire-log detail below.') }}</p>
                        </div>
                        <x-ui.button wire:click="refreshInspectorLists" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Run') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Agent') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provider') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Turn') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-default bg-surface-card">
                                @forelse ($recentRuns as $run)
                                    <tr wire:key="recent-run-{{ $run['run_id'] }}" class="hover:bg-surface-subtle/60 transition-colors">
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <p class="font-mono text-xs text-ink">{{ $run['run_id'] }}</p>
                                            <p class="mt-1 text-xs text-muted tabular-nums">{{ $run['started_at'] ?? $run['recorded_at'] }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-ink">{{ $run['employee_name'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <p class="text-ink">{{ $run['provider'] }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ $run['model'] }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <x-ui.badge :variant="$run['status_color'] ?? ($run['outcome_color'] ?? 'secondary')">
                                                {{ $run['status_label'] ?? ($run['outcome_label'] ?? __('Unknown')) }}
                                            </x-ui.badge>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            @if ($run['turn_id'])
                                                <button
                                                    type="button"
                                                    wire:click="inspectRecentTurn('{{ $run['turn_id'] }}')"
                                                    class="font-mono text-xs text-accent hover:underline"
                                                >
                                                    {{ Str::limit($run['turn_id'], 18, '...') }}
                                                </button>
                                            @else
                                                <span class="text-muted">---</span>
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-right">
                                            <x-ui.button wire:click="inspectRecentRun('{{ $run['run_id'] }}')" variant="secondary" size="sm">
                                                {{ __('Inspect') }}
                                            </x-ui.button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No runs have been recorded yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                @if ($runView)
                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Run Details') }}</h3>
                        @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $runView['inspection']])
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Activity Transcript') }}</h3>
                        @include('livewire.admin.ai.control-plane.partials.activity-transcript', [
                            'transcript' => $runView['transcript'],
                            'triggeringPrompt' => $runView['triggering_prompt'],
                        ])
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Wire Log') }}</h3>
                        @include('livewire.admin.ai.control-plane.partials.wire-log', [
                            'entries' => $runView['wire_log_entries'],
                            'wireLoggingEnabled' => $runView['wire_logging_enabled'],
                        ])
                    </x-ui.card>
                @endif
            </div>
        </x-ui.tab>

        <x-ui.tab id="turns">
            <div class="space-y-section-gap">
                <x-ui.card>
                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
                        <div class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                <x-ui.input
                                    id="inspect-turn-id"
                                    wire:model="inspectTurnId"
                                    :label="__('Turn ID')"
                                    :placeholder="__('01H...')"
                                />
                                <div class="flex items-end">
                                    <x-ui.button wire:click="inspectTurn" variant="primary" size="sm">
                                        {{ __('Inspect Turn') }}
                                    </x-ui.button>
                                </div>
                            </div>

                            @if ($turnInspectionError)
                                <x-ui.alert variant="warning">{{ $turnInspectionError }}</x-ui.alert>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-border-default bg-surface-subtle p-card-inner">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Recent Turns') }}</p>
                            <p class="mt-2 text-sm text-muted">{{ __('Use the list below to jump directly into queue, cancellation, and timeline diagnostics.') }}</p>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-medium text-ink">{{ __('Recent Turns') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Newest turns first, with direct access to the current run when present.') }}</p>
                        </div>
                        <x-ui.button wire:click="refreshInspectorLists" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Turn') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Agent') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Cancel') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-default bg-surface-card">
                                @forelse ($recentTurns as $turn)
                                    <tr wire:key="recent-turn-{{ $turn['id'] }}" class="hover:bg-surface-subtle/60 transition-colors">
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <p class="font-mono text-xs text-ink">{{ Str::limit($turn['id'], 24, '...') }}</p>
                                            <p class="mt-1 text-xs text-muted tabular-nums">{{ $turn['created_at'] }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-ink">{{ $turn['employee_name'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <x-ui.badge :variant="$turn['status_color']">{{ $turn['status_label'] }}</x-ui.badge>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            @if ($turn['cancel_mode_label'])
                                                <x-ui.badge variant="warning">{{ $turn['cancel_mode_label'] }}</x-ui.badge>
                                            @else
                                                <span class="text-muted">---</span>
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-right">
                                            <x-ui.button wire:click="inspectRecentTurn('{{ $turn['id'] }}')" variant="secondary" size="sm">
                                                {{ __('Inspect') }}
                                            </x-ui.button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No turns have been recorded yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                @if ($turnView)
                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Turn Details') }}</h3>
                        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Turn ID') }}</dt>
                                <dd class="mt-1 font-mono text-xs text-ink">{{ $turnView['turn']['id'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</dt>
                                <dd class="mt-1"><x-ui.badge :variant="$turnView['turn']['status_color']">{{ $turnView['turn']['status_label'] }}</x-ui.badge></dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Current Phase') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $turnView['turn']['current_phase_label'] ?? '---' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Current Run') }}</dt>
                                <dd class="mt-1">
                                    @if ($turnView['turn']['current_run_id'])
                                        <a
                                            href="{{ route('admin.ai.control-plane', array_merge($controlPlaneContext, ['tab' => 'inspector', 'runId' => $turnView['turn']['current_run_id']])) }}"
                                            wire:navigate
                                            class="font-mono text-xs text-accent hover:underline"
                                        >
                                            {{ $turnView['turn']['current_run_id'] }}
                                        </a>
                                    @else
                                        <span class="text-muted">---</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Agent') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $turnView['turn']['employee_name'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Cancel Mode') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $turnView['turn']['cancel_mode_label'] ?? '---' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Cancel Requested') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $turnView['turn']['cancel_requested_at'] ?? '---' }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Terminal Event') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $turnView['turn']['cancel_terminal_at'] ?? '---' }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>

                    <x-ui.card>
                        <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Timeline') }}</h3>
                        @include('livewire.admin.ai.control-plane.partials.turn-timeline', [
                            'timeline' => $turnView['timeline'],
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
                            <h3 class="text-sm font-medium text-ink">{{ __('Turn Queue Health') }}</h3>
                            <p class="mt-1 text-xs text-muted">{{ __('Queue saturation, stale turns, and recent failures are surfaced separately so operators can distinguish load from breakage.') }}</p>
                        </div>
                        <x-ui.button wire:click="refreshHealthSnapshots" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    @if ($turnHealthCounts)
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Active Turns') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight text-ink">{{ $turnHealthCounts['queued'] + $turnHealthCounts['booting'] + $turnHealthCounts['running'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Stale Turns') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight {{ ($turnHealthCounts['stale_queued'] + $turnHealthCounts['stale_running']) > 0 ? 'text-danger' : 'text-ink' }}">{{ $turnHealthCounts['stale_queued'] + $turnHealthCounts['stale_running'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Completed Last Hour') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight text-success">{{ $turnHealthCounts['completed_last_hour'] }}</p>
                            </div>
                            <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner">
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Failed Last Hour') }}</p>
                                <p class="mt-2 text-2xl font-medium tracking-tight {{ $turnHealthCounts['failed_last_hour'] > 0 ? 'text-warning' : 'text-ink' }}">{{ $turnHealthCounts['failed_last_hour'] }}</p>
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

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Tool') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Readiness') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Health') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Presence') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Explanation') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-default bg-surface-card">
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
                            </tbody>
                        </table>
                    </div>
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
