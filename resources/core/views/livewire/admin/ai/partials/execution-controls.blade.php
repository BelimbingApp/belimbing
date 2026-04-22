<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/**
 * @var array<string, mixed>|null $schema
 * @var string $statePath
 * @var string|null $subtitle
 */
?>
@if (is_array($schema) && ($schema['groups'] ?? []) !== [])
    <div class="mt-4 space-y-4">
        <div class="space-y-1">
            <h4 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Execution Controls') }}</h4>
            @if (filled($subtitle ?? null))
                <p class="text-xs text-muted">{{ $subtitle }}</p>
            @endif
        </div>

        @foreach (($schema['notes'] ?? []) as $note)
            <x-ui.alert variant="info">
                {{ __($note) }}
            </x-ui.alert>
        @endforeach

        @foreach ($schema['groups'] as $group)
            <div class="space-y-3">
                <div class="space-y-1">
                    <h5 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __($group['label']) }}</h5>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($group['controls'] as $control)
                        @php
                            $inputId = str($statePath . '.' . $control['path'])->replace(['.', '_'], '-')->value();
                            $binding = $statePath . '.' . $control['path'];
                            $description = $control['description'] ?? null;
                            $defaultValue = $control['default_value'] ?? null;
                        @endphp

                        <div class="space-y-2">
                            @if (($control['editable'] ?? false) === true)
                                @if (($control['type'] ?? null) === 'select')
                                    <x-ui.select
                                        id="{{ $inputId }}"
                                        label="{{ __($control['label']) }}"
                                        wire:model.live="{{ $binding }}"
                                    >
                                        @foreach (($control['options'] ?? []) as $option)
                                            <option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>
                                        @endforeach
                                    </x-ui.select>
                                @elseif (($control['type'] ?? null) === 'checkbox')
                                    <x-ui.checkbox
                                        id="{{ $inputId }}"
                                        label="{{ __($control['label']) }}"
                                        wire:model.live="{{ $binding }}"
                                    />
                                @else
                                    <x-ui.input
                                        id="{{ $inputId }}"
                                        type="number"
                                        label="{{ __($control['label']) }}"
                                        wire:model.live.debounce.300ms="{{ $binding }}"
                                        @if(isset($control['min']) && $control['min'] !== null) min="{{ $control['min'] }}" @endif
                                        @if(isset($control['max']) && $control['max'] !== null) max="{{ $control['max'] }}" @endif
                                        @if(isset($control['step']) && $control['step'] !== null) step="{{ $control['step'] }}" @endif
                                    />
                                @endif
                            @else
                                <div class="space-y-1">
                                    <p class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __($control['label']) }}</p>
                                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-border-input bg-surface-subtle px-input-x py-input-y">
                                        <span class="text-sm font-medium text-ink">{{ $control['display_text'] }}</span>
                                        <x-ui.badge variant="info">{{ __($control['read_only_reason'] ?? 'Read only') }}</x-ui.badge>
                                    </div>
                                </div>
                            @endif

                            @if (filled($description))
                                <p class="text-xs text-muted">{{ __($description) }}</p>
                            @endif

                            <p class="text-xs text-muted">
                                {{ __('Default: :value', ['value' => $defaultValue === null || $defaultValue === '' ? __('System default') : $defaultValue]) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
