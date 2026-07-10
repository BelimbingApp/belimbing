@props([
    'idPrefix',
    'period',
    'periodOptions',
    'from' => '',
    'to' => '',
    'rangeError' => null,
    'periodModel' => 'period',
    'fromModel' => 'from',
    'toModel' => 'to',
    'periodLabel' => null,
    'fromLabel' => null,
    'toLabel' => null,
    'variant' => 'form',
])

@php($toolbar = $variant === 'toolbar')

<div {{ $attributes->class([
    'flex flex-col gap-3 sm:flex-row lg:flex-none' => $toolbar,
    'grid gap-3 md:grid-cols-3' => ! $toolbar,
]) }}>
    <div @class(['w-full sm:w-40' => $toolbar])>
        @if ($toolbar)
            <label class="sr-only" for="{{ $idPrefix }}-period">{{ $periodLabel ?? __('Period') }}</label>
        @endif

        <x-ui.select
            id="{{ $idPrefix }}-period"
            wire:model.live="{{ $periodModel }}"
            :label="$toolbar ? null : ($periodLabel ?? __('Period'))"
        >
            @foreach ($periodOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-ui.select>
    </div>

    @if ($period === 'custom')
        @if ($toolbar)
            <x-ui.button
                variant="control"
                wire:click="openPeriodRangeModal"
                class="w-full whitespace-nowrap tabular-nums sm:w-auto"
                title="{{ __('Change custom date range') }}"
            >
                <x-icon name="heroicon-o-calendar-days" class="h-4 w-4 text-muted" />
                @if ($from !== '' && $to !== '')
                    <x-ui.datetime :value="$from" format="date" />
                    <x-icon name="heroicon-o-chevron-right" class="h-3.5 w-3.5 text-muted" aria-hidden="true" />
                    <x-ui.datetime :value="$to" format="date" />
                @else
                    {{ __('Choose dates') }}
                @endif
            </x-ui.button>
        @else
            <div>
                <x-ui.input
                    id="{{ $idPrefix }}-from"
                    type="date"
                    wire:model.live.debounce.500ms="{{ $fromModel }}"
                    :label="$fromLabel ?? __('From')"
                />
            </div>
            <div>
                <x-ui.input
                    id="{{ $idPrefix }}-to"
                    type="date"
                    wire:model.live.debounce.500ms="{{ $toModel }}"
                    :label="$toLabel ?? __('To')"
                />
            </div>
        @endif
    @endif
</div>

@if ($toolbar)
    <x-ui.modal wire:model.live="periodRangeModalOpen" class="max-w-md">
        <form wire:submit="applyPeriodRangeModal" class="space-y-5 p-5 sm:p-6">
            <div>
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Custom date range') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Choose the first and last dates to include.') }}</p>
            </div>

            @if ($rangeError)
                <x-ui.alert variant="danger">{{ $rangeError }}</x-ui.alert>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input
                    id="{{ $idPrefix }}-draft-from"
                    type="date"
                    wire:model="periodDraftFrom"
                    :label="$fromLabel ?? __('From')"
                />
                <x-ui.input
                    id="{{ $idPrefix }}-draft-to"
                    type="date"
                    wire:model="periodDraftTo"
                    :label="$toLabel ?? __('To')"
                />
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button variant="ghost" wire:click="cancelPeriodRangeModal">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="primary">
                    {{ __('Apply range') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
@endif
