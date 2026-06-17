<?php
/** @var \App\Modules\Core\AI\Livewire\Setup\Lara $this */
?>
<div>
    <x-slot name="title">{{ $laraActivated ? __('Lara') : __('Set Up Lara') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="$laraActivated ? __('Lara') : __('Set Up Lara')"
            :subtitle="__('Inspect and override Lara\'s harness — system prompt, operator context, and tool notes. Model selection lives on the providers page.')"
        />

        @if (! $licenseeExists)
            <x-ui.alert variant="warning">
                {{ __('The Licensee company must be set up before Lara can be provisioned.') }}
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Licensee') }}
                </a>
            </x-ui.alert>
        @elseif (! $laraExists)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Provision Lara') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Lara\'s employee record does not exist yet. Provision her to create the system Agent record for the Licensee company.') }}</p>
                <form wire:submit="provisionLara">
                    <x-ui.button type="submit" variant="primary">{{ __('Provision Lara') }}</x-ui.button>
                </form>
            </x-ui.card>
        @else
            {{-- Activation status --}}
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-3">{{ __('Status') }}</h3>

                @if ($laraActivated)
                    <div class="flex items-baseline gap-3">
                        <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                        <span class="text-sm text-muted">{{ __('Default model:') }}</span>
                        <span class="text-sm font-medium text-ink font-mono">
                            {{ ($defaultConfig['provider_name'] ?? '—') . '/' . ($defaultConfig['model'] ?? '—') }}
                        </span>
                    </div>
                    <p class="text-xs text-muted mt-2">
                        {{ __('Set on') }}
                        <a href="{{ route('admin.ai.providers') }}" wire:navigate class="text-accent hover:underline">{{ __('AI Providers') }}</a>
                        {{ __('— Lara uses whichever provider/model wins by priority.') }}
                    </p>
                @else
                    <div class="flex items-baseline gap-3">
                        <x-ui.badge variant="warning">{{ __('Inactive') }}</x-ui.badge>
                        <span class="text-sm text-muted">{{ __('No active provider in the priority chain.') }}</span>
                    </div>
                    <p class="text-xs text-muted mt-2">
                        {{ __('Connect at least one provider with an active model on') }}
                        <a href="{{ route('admin.ai.providers') }}" wire:navigate class="text-accent hover:underline">{{ __('AI Providers') }}</a>.
                    </p>
                @endif
            </x-ui.card>

            {{-- Interactive tool surface --}}
            <x-ui.card>
                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between mb-4">
                    <div>
                        <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Interactive Tools') }}</h3>
                        <p class="text-xs text-muted mt-1">{{ __('Tools Lara can use from the in-page chat. Defaults are fixed; additional tools are opt-in.') }}</p>
                    </div>
                    <x-ui.button variant="ghost" size="sm" href="{{ route('admin.ai.tools') }}" wire:navigate>
                        {{ __('Open Tool Catalog') }}
                    </x-ui.button>
                </div>

                <div class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                    <div class="rounded-2xl border border-border-default bg-surface-subtle/30 p-4">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <p class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Enabled in Lara Chat') }}</p>
                            <x-ui.badge variant="success">{{ trans_choice(':count tool|:count tools', count($enabledToolRows), ['count' => count($enabledToolRows)]) }}</x-ui.badge>
                        </div>
                        <div class="space-y-3">
                            @foreach ($enabledToolRows as $tool)
                                <div wire:key="lara-enabled-tool-{{ $tool['name'] }}" class="rounded-xl border border-border-default/70 bg-surface-card p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-sm font-medium text-ink" title="{{ $tool['name'] }}">{{ $tool['displayName'] }}</span>
                                                <x-ui.badge variant="{{ $tool['isDefault'] ? 'info' : 'accent' }}">{{ $tool['isDefault'] ? __('Default') : __('Added') }}</x-ui.badge>
                                                <x-ui.badge variant="{{ $tool['readinessColor'] }}">{{ $tool['readinessLabel'] }}</x-ui.badge>
                                            </div>
                                            @if ($tool['summary'] !== '')
                                                <p class="text-xs text-muted mt-1">{{ $tool['summary'] }}</p>
                                            @endif
                                        </div>

                                        @unless ($tool['isDefault'])
                                            <x-ui.icon-action
                                                icon="heroicon-o-x-mark"
                                                :label="__('Remove :tool from Lara Chat', ['tool' => $tool['displayName']])"
                                                :title="__('Remove')"
                                                wire:click="toggleExtraTool('{{ $tool['name'] }}')"
                                            />
                                        @endunless
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <p class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Available to Add') }}</p>
                            <span class="text-xs text-muted">{{ __('Optional tools beyond the default set.') }}</span>
                        </div>

                        @if ($availableToolRows === [])
                            <p class="text-xs text-muted">{{ __('No optional tools are available to add.') }}</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($availableToolRows as $tool)
                                    <div wire:key="lara-available-tool-{{ $tool['name'] }}" class="rounded-xl border border-border-default/70 bg-surface-subtle/20 p-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="text-sm font-medium text-ink" title="{{ $tool['name'] }}">{{ $tool['displayName'] }}</span>
                                                    <x-ui.badge variant="{{ $tool['riskColor'] }}">{{ $tool['riskLabel'] }}</x-ui.badge>
                                                    <x-ui.badge variant="{{ $tool['readinessColor'] }}">{{ $tool['readinessLabel'] }}</x-ui.badge>
                                                </div>
                                                @if ($tool['summary'] !== '')
                                                    <p class="text-xs text-muted mt-1">{{ $tool['summary'] }}</p>
                                                @endif
                                            </div>
                                            <x-ui.button
                                                variant="ghost"
                                                size="sm"
                                                wire:click="toggleExtraTool('{{ $tool['name'] }}')"
                                            >
                                                <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                                {{ __('Add') }}
                                            </x-ui.button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </x-ui.card>

            {{-- Harness inspector --}}
            <x-ui.card>
                <div class="flex items-baseline justify-between mb-3">
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Harness') }}</h3>
                    <span class="text-[10px] text-muted font-mono">{{ $workspacePath }}</span>
                </div>
                <p class="text-xs text-muted mb-4">{{ __('Each slot is resolved from the workspace first, then the framework default. Override to copy the default into the workspace and edit it; revert to delete the workspace copy and fall back to the framework default.') }}</p>

                <x-ui.table container="flush" :caption="__('Lara setup')">

                    <x-slot name="head">
                            <tr>
                                <x-ui.th>{{ __('Slot') }}</x-ui.th>
                                <x-ui.th>{{ __('File') }}</x-ui.th>
                                <x-ui.th>{{ __('Source') }}</x-ui.th>
                                <x-ui.th align="right" class="hidden md:table-cell">{{ __('Size') }}</x-ui.th>
                                <x-ui.th class="hidden md:table-cell">{{ __('Last Edit') }}</x-ui.th>
                                <x-ui.th align="right">{{ __('Actions') }}</x-ui.th>
                            </tr>
                        </x-slot>

                            @foreach ($slots as $slot)
                                <tr wire:key="slot-{{ $slot['slot'] }}">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">{{ $slot['label'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted font-mono">{{ $slot['filename'] }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        @if ($slot['source'] === 'workspace')
                                            <x-ui.badge variant="info">{{ __('Workspace override') }}</x-ui.badge>
                                        @elseif ($slot['source'] === 'framework')
                                            <x-ui.badge variant="default">{{ __('Framework default') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge variant="warning">{{ __('Missing') }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted tabular-nums text-right">
                                        {{ $slot['byteSize'] !== null ? number_format($slot['byteSize']).' B' : '—' }}
                                    </td>
                                    <td class="hidden md:table-cell px-table-cell-x py-table-cell-y whitespace-nowrap text-xs text-muted">
                                        @if (isset($slot['audit']) && is_array($slot['audit']))
                                            @php
                                                $editedAt = $slot['audit']['edited_at'] ?? null;
                                                $editedAtTs = is_string($editedAt) ? strtotime($editedAt) : false;
                                                $relative = $editedAtTs !== false ? \Illuminate\Support\Carbon::createFromTimestamp($editedAtTs)->diffForHumans() : null;
                                            @endphp
                                            <span class="font-medium text-ink">{{ $slot['audit']['user_name'] ?? __('unknown') }}</span>
                                            @if ($relative !== null)
                                                <span class="text-muted">· {{ $relative }}</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                        <x-ui.icon-action-group>
                                            @if ($slot['isOverridden'])
                                                <x-ui.icon-action
                                                    icon="heroicon-o-pencil-square"
                                                    :label="__('Edit workspace override')"
                                                    :title="__('Edit')"
                                                    wire:click="editSlot('{{ $slot['slot'] }}')"
                                                />
                                                <x-ui.icon-action
                                                    icon="heroicon-o-arrow-uturn-left"
                                                    :label="__('Revert to framework default')"
                                                    :title="__('Revert')"
                                                    wire:click="revertSlot('{{ $slot['slot'] }}')"
                                                />
                                            @elseif ($slot['exists'])
                                                <x-ui.icon-action
                                                    icon="heroicon-o-eye"
                                                    :label="__('View framework default')"
                                                    :title="__('View')"
                                                    wire:click="editSlot('{{ $slot['slot'] }}')"
                                                />
                                                <x-ui.icon-action
                                                    icon="heroicon-o-document-duplicate"
                                                    :label="__('Override with a workspace copy')"
                                                    :title="__('Override')"
                                                    wire:click="overrideSlot('{{ $slot['slot'] }}')"
                                                />
                                            @else
                                                <span class="text-xs text-muted">{{ __('No content') }}</span>
                                            @endif
                                        </x-ui.icon-action-group>
                                    </td>
                                </tr>
                            @endforeach


                </x-ui.table>
            </x-ui.card>

            {{-- Task models cross-link --}}
            @if ($laraActivated)
                <x-ui.card>
                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div class="space-y-2">
                            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Task Models') }}</h3>
                            @if ($taskSummaries === [])
                                <p class="text-xs text-muted">{{ __('No task models have been configured yet.') }}</p>
                            @else
                                @foreach ($taskSummaries as $taskSummary)
                                    <div class="flex items-baseline gap-3">
                                        <span class="text-sm text-muted">{{ __($taskSummary['label']) }}</span>
                                        <span class="text-sm font-medium text-ink">{{ $taskSummary['summary'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                        <x-ui.button variant="ghost" href="{{ route('admin.ai.task-models') }}" wire:navigate>
                            {{ __('Manage Task Models') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            @endif
        @endif
    </div>

    {{-- Slot editor modal --}}
    <x-ui.modal wire:model="showEditorModal" class="max-w-3xl">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('Edit Harness Slot') }}</h3>
                    @if ($editingSlot !== null)
                        <p class="text-xs text-muted mt-0.5 font-mono">{{ $editingSlot }}.md</p>
                    @endif
                </div>
                <button wire:click="closeSlotEditor" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            @if ($editingSlot !== null)
                @php
                    $editorWarnings = $this->editorWarnings;
                    $assembledPreview = null;
                @endphp

                <x-ui.tabs
                    :tabs="[
                        ['id' => 'edit', 'label' => __('Edit')],
                        ['id' => 'preview', 'label' => __('Preview Assembled Prompt')],
                    ]"
                    default="edit"
                    size="sm"
                    persistence="none"
                >
                    <x-ui.tab id="edit" class="space-y-3">
                        <textarea
                            wire:model.live.debounce.300ms="editingContent"
                            aria-label="{{ __('Prompt slot content') }}"
                            rows="20"
                            class="w-full rounded-2xl border border-border-input bg-surface-card text-ink font-mono text-xs px-input-x py-input-y focus:ring-2 focus:ring-accent focus:ring-offset-2"
                            spellcheck="false"
                        ></textarea>

                        @if ($editorWarnings !== [])
                            <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 space-y-1">
                                <p class="text-[11px] uppercase tracking-wider font-semibold text-amber-700 dark:text-amber-400">{{ __('Lint warnings') }}</p>
                                @foreach ($editorWarnings as $warning)
                                    <p class="text-xs text-amber-700 dark:text-amber-400">• {{ $warning }}</p>
                                @endforeach
                                <p class="text-[11px] text-amber-700/80 dark:text-amber-400/80 pt-1">
                                    {{ __('Warnings do not block save — fix or proceed at your discretion.') }}
                                </p>
                            </div>
                        @endif

                        <p class="text-xs text-muted">{{ __('Saving writes to the workspace path. Revert from the slot row to delete the workspace copy and fall back to the framework default.') }}</p>
                    </x-ui.tab>

                    <x-ui.tab id="preview" class="space-y-2">
                        @php($assembledPreview = $this->assembledPreview)
                        <p class="text-xs text-muted">{{ __('Static workspace assembly: prompt-content slots concatenated in load order. Runtime context (page, capabilities, JSON state) is injected separately at request time and is not shown here.') }}</p>
                        <div class="max-h-[28rem] overflow-y-auto rounded-2xl border border-border-default bg-surface-subtle/30 px-3 py-2 text-xs font-mono text-ink whitespace-pre-wrap">{{ $assembledPreview !== '' ? $assembledPreview : __('(empty)') }}</div>
                    </x-ui.tab>
                </x-ui.tabs>
            @endif

            <div class="mt-4 flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="closeSlotEditor">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="saveSlot">{{ __('Save') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
