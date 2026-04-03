<?php

use App\Modules\Core\AI\Livewire\ControlPlane;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var ControlPlane $this */
?>
<div>
    <x-slot name="title">{{ __('Control Plane') }}</x-slot>

    <x-ui.page-header :title="__('Operator Control Plane')" :subtitle="__('Inspect runs, monitor health and presence, and manage lifecycle operations for AI subsystems.')">
        <x-slot name="help">
            <div class="space-y-3">
                <p>{{ __('The Control Plane provides a unified operator view of the AI runtime. It connects run inspection, health monitoring, and lifecycle management into one coherent surface.') }}</p>

                <div>
                    <p class="font-medium text-ink">{{ __('Run Inspector') }}</p>
                    <p class="text-muted mt-1">{{ __('Inspect individual runs or entire sessions. See provider path, tool actions, timing, and outcome for each run.') }}</p>
                </div>

                <div>
                    <p class="font-medium text-ink">{{ __('Health & Presence') }}</p>
                    <p class="text-muted mt-1">{{ __('View readiness, health, and presence as distinct dimensions for tools, agents, and providers.') }}</p>
                </div>

                <div>
                    <p class="font-medium text-ink">{{ __('Lifecycle Controls') }}</p>
                    <p class="text-muted mt-1">{{ __('Preview and execute compaction, pruning, and sweep operations with full audit trail.') }}</p>
                </div>
            </div>
        </x-slot>
    </x-ui.page-header>

    <x-ui.tabs :tabs="[
        ['id' => 'inspector', 'label' => __('Run Inspector'), 'icon' => 'heroicon-o-magnifying-glass'],
        ['id' => 'health', 'label' => __('Health & Presence'), 'icon' => 'heroicon-o-heart'],
        ['id' => 'lifecycle', 'label' => __('Lifecycle Controls'), 'icon' => 'heroicon-o-arrow-path'],
    ]" default="inspector">

        {{-- ============================================================ --}}
        {{-- TAB: Run Inspector                                           --}}
        {{-- ============================================================ --}}
        <x-ui.tab id="inspector">
            <div class="space-y-section-gap">
                <x-ui.card>
                    <div class="space-y-4">
                        <h3 class="text-sm font-medium text-ink">{{ __('Inspect Run') }}</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <x-ui.input
                                id="inspect-employee-id"
                                wire:model="inspectEmployeeId"
                                type="number"
                                :label="__('Employee ID')"
                                :placeholder="__('e.g. 1')"
                            />
                            <x-ui.input
                                id="inspect-run-id"
                                wire:model="inspectRunId"
                                :label="__('Run ID')"
                                :placeholder="__('e.g. run_abc123')"
                            />
                            <x-ui.input
                                id="inspect-session-id"
                                wire:model="inspectSessionId"
                                :label="__('Session ID')"
                                :placeholder="__('e.g. sess_xyz')"
                            />
                        </div>
                        <div class="flex gap-2">
                            <x-ui.button wire:click="inspectRun" variant="primary" size="sm">
                                {{ __('Inspect Run') }}
                            </x-ui.button>
                            <x-ui.button wire:click="inspectSession" variant="secondary" size="sm">
                                {{ __('Inspect Session') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                @if($inspectionError)
                    <x-ui.alert variant="warning">{{ $inspectionError }}</x-ui.alert>
                @endif

                {{-- Single run result --}}
                @if($singleRunInspection)
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-ink mb-3">{{ __('Run Details') }}</h3>
                        @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $singleRunInspection])
                    </x-ui.card>
                @endif

                {{-- Session run list --}}
                @if($runInspections !== [])
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-ink mb-3">{{ __('Session Runs') }} ({{ count($runInspections) }})</h3>
                        <div class="space-y-3">
                            @foreach($runInspections as $idx => $run)
                                @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $run])
                                @if(!$loop->last)
                                    <hr class="border-border-default" />
                                @endif
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif
            </div>
        </x-ui.tab>

        {{-- ============================================================ --}}
        {{-- TAB: Health & Presence                                       --}}
        {{-- ============================================================ --}}
        <x-ui.tab id="health">
            <div class="space-y-section-gap">
                {{-- Tool health overview --}}
                <x-ui.card>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-ink">{{ __('Tool Health') }}</h3>
                        <x-ui.button wire:click="loadToolSnapshots" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    @if($toolSnapshots !== [])
                        <div class="overflow-x-auto -mx-card-inner px-card-inner">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Tool') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Readiness') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Health') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Presence') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted hidden md:table-cell">{{ __('Explanation') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @foreach($toolSnapshots as $snapshot)
                                        <tr wire:key="tool-health-{{ $snapshot['target_id'] }}" class="hover:bg-surface-subtle/50 transition-colors">
                                            <td class="px-table-cell-x py-table-cell-y font-medium text-ink">{{ $snapshot['target_id'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                <x-ui.badge :variant="$snapshot['readiness_color']">{{ $snapshot['readiness_label'] }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                <x-ui.badge :variant="$snapshot['health_color']">{{ $snapshot['health_label'] }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                <x-ui.badge :variant="$snapshot['presence_color']">{{ $snapshot['presence_label'] }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted hidden md:table-cell">{{ $snapshot['explanation'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-muted">{{ __('Click Refresh to load tool health snapshots.') }}</p>
                    @endif
                </x-ui.card>

                {{-- Agent health --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-ink mb-3">{{ __('Agent Health') }}</h3>
                    <div class="flex items-end gap-3">
                        <x-ui.input
                            id="health-agent-id"
                            wire:model="healthAgentId"
                            type="number"
                            :label="__('Agent Employee ID')"
                            :placeholder="__('e.g. 1')"
                        />
                        <x-ui.button wire:click="loadAgentSnapshot" variant="secondary" size="sm">
                            {{ __('Check') }}
                        </x-ui.button>
                    </div>

                    @if($agentSnapshot)
                        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Readiness') }}</span>
                                <div class="mt-1"><x-ui.badge :variant="$agentSnapshot['readiness_color']">{{ $agentSnapshot['readiness_label'] }}</x-ui.badge></div>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Health') }}</span>
                                <div class="mt-1"><x-ui.badge :variant="$agentSnapshot['health_color']">{{ $agentSnapshot['health_label'] }}</x-ui.badge></div>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Presence') }}</span>
                                <div class="mt-1"><x-ui.badge :variant="$agentSnapshot['presence_color']">{{ $agentSnapshot['presence_label'] }}</x-ui.badge></div>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Measured') }}</span>
                                <div class="mt-1 text-sm text-ink tabular-nums">{{ $agentSnapshot['measured_at'] }}</div>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-muted">{{ $agentSnapshot['explanation'] }}</p>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.tab>

        {{-- ============================================================ --}}
        {{-- TAB: Lifecycle Controls                                      --}}
        {{-- ============================================================ --}}
        <x-ui.tab id="lifecycle">
            <div class="space-y-section-gap">
                {{-- Action form --}}
                <x-ui.card>
                    <h3 class="text-sm font-medium text-ink mb-3">{{ __('Lifecycle Action') }}</h3>
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label for="lifecycle-action" class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Action') }}</label>
                                <select
                                    id="lifecycle-action"
                                    wire:model.live="lifecycleAction"
                                    class="mt-1 w-full rounded-xl border border-border-input bg-surface-card text-ink text-sm px-input-x py-input-y focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                >
                                    <option value="">{{ __('Select action...') }}</option>
                                    @foreach(\App\Modules\Core\AI\Enums\LifecycleAction::cases() as $action)
                                        <option value="{{ $action->value }}">{{ $action->label() }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Context-dependent fields --}}
                            @if(in_array($lifecycleAction, ['compact_memory', 'prune_sessions']))
                                <x-ui.input
                                    id="lifecycle-employee-id"
                                    wire:model="lifecycleEmployeeId"
                                    type="number"
                                    :label="__('Employee ID')"
                                    :placeholder="__('e.g. 1')"
                                />
                            @endif

                            @if($lifecycleAction === 'prune_sessions')
                                <x-ui.input
                                    id="lifecycle-retention-days"
                                    wire:model="lifecycleRetentionDays"
                                    type="number"
                                    :label="__('Retention Days')"
                                    :placeholder="__('30')"
                                />
                            @endif

                            @if($lifecycleAction === 'sweep_operations')
                                <x-ui.input
                                    id="lifecycle-stale-minutes"
                                    wire:model="lifecycleStaleMinutes"
                                    type="number"
                                    :label="__('Stale Minutes')"
                                    :placeholder="__('30')"
                                />
                            @endif

                            @if($lifecycleAction === 'prune_artifacts')
                                <x-ui.input
                                    id="lifecycle-session-id"
                                    wire:model="lifecycleSessionId"
                                    :label="__('Session ID')"
                                    :placeholder="__('Optional — scope to a session')"
                                />
                            @endif
                        </div>

                        <div class="flex gap-2">
                            <x-ui.button wire:click="previewLifecycleAction" variant="secondary" size="sm">
                                {{ __('Preview') }}
                            </x-ui.button>
                            <x-ui.button
                                wire:click="executeLifecycleAction"
                                wire:confirm="{{ __('This will execute the lifecycle action. Continue?') }}"
                                variant="primary"
                                size="sm"
                            >
                                {{ __('Execute') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>

                @if($lifecycleError)
                    <x-ui.alert variant="warning">{{ $lifecycleError }}</x-ui.alert>
                @endif

                {{-- Preview result --}}
                @if($lifecyclePreview)
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-ink mb-3">{{ __('Preview') }}</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Action') }}</span>
                                <p class="text-sm text-ink mt-1">{{ $lifecyclePreview['action_label'] }}</p>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Affected') }}</span>
                                <p class="text-sm text-ink mt-1 tabular-nums">{{ $lifecyclePreview['affected_count'] }}</p>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Destructive') }}</span>
                                <p class="mt-1">
                                    <x-ui.badge :variant="$lifecyclePreview['is_destructive'] ? 'danger' : 'success'">
                                        {{ $lifecyclePreview['is_destructive'] ? __('Yes') : __('No') }}
                                    </x-ui.badge>
                                </p>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Generated') }}</span>
                                <p class="text-sm text-ink mt-1 tabular-nums">{{ $lifecyclePreview['generated_at'] }}</p>
                            </div>
                        </div>
                        @if($lifecyclePreview['affected_summary'] !== [])
                            <div class="border-t border-border-default pt-3">
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Summary') }}</span>
                                <ul class="mt-1 space-y-1">
                                    @foreach($lifecyclePreview['affected_summary'] as $line)
                                        <li class="text-sm text-muted">{{ $line }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                {{-- Execution result --}}
                @if($lifecycleResult)
                    <x-ui.card>
                        <h3 class="text-sm font-medium text-ink mb-3">{{ __('Execution Result') }}</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Request ID') }}</span>
                                <p class="text-sm text-ink mt-1 font-mono tabular-nums">{{ $lifecycleResult['request_id'] }}</p>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</span>
                                <p class="mt-1"><x-ui.badge :variant="$lifecycleResult['status_color']">{{ $lifecycleResult['status_label'] }}</x-ui.badge></p>
                            </div>
                            <div>
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Executed') }}</span>
                                <p class="text-sm text-ink mt-1 tabular-nums">{{ $lifecycleResult['executed_at'] ?? '—' }}</p>
                            </div>
                            @if($lifecycleResult['error_message'])
                                <div>
                                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Error') }}</span>
                                    <p class="text-sm text-danger mt-1">{{ $lifecycleResult['error_message'] }}</p>
                                </div>
                            @endif
                        </div>
                        @if($lifecycleResult['result'])
                            <div class="border-t border-border-default pt-3 mt-3">
                                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Result') }}</span>
                                <pre class="mt-1 text-xs text-muted bg-surface-subtle rounded-lg p-3 overflow-x-auto">{{ json_encode($lifecycleResult['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        @endif
                    </x-ui.card>
                @endif

                {{-- Recent lifecycle requests --}}
                <x-ui.card>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-ink">{{ __('Recent Requests') }}</h3>
                        <x-ui.button wire:click="loadRecentLifecycleRequests" variant="secondary" size="sm">
                            {{ __('Refresh') }}
                        </x-ui.button>
                    </div>

                    @if($recentLifecycleRequests !== [])
                        <div class="overflow-x-auto -mx-card-inner px-card-inner">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('ID') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Action') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted hidden md:table-cell">{{ __('Created') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted hidden lg:table-cell">{{ __('Executed') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-surface-card divide-y divide-border-default">
                                    @foreach($recentLifecycleRequests as $req)
                                        <tr wire:key="lc-{{ $req['request_id'] }}" class="hover:bg-surface-subtle/50 transition-colors">
                                            <td class="px-table-cell-x py-table-cell-y font-mono text-xs tabular-nums">{{ $req['request_id'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y">{{ $req['action_label'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                                <x-ui.badge :variant="$req['status_color']">{{ $req['status_label'] }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums hidden md:table-cell">{{ $req['created_at'] }}</td>
                                            <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums hidden lg:table-cell">{{ $req['executed_at'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-muted">{{ __('Click Refresh to load recent lifecycle requests, or execute an action above.') }}</p>
                    @endif
                </x-ui.card>
            </div>
        </x-ui.tab>

    </x-ui.tabs>
</div>
