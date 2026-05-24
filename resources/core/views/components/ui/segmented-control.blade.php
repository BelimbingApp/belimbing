{{--
    Segmented control: compact mutually exclusive choices.

    Props:
        options - List of ['value' => string, 'label' => string, 'icon' => string, 'disabled' => bool].
                  'id' is accepted as a value alias for tab-style option arrays.
        value   - Initial selected value when not bound with x-model.
        label   - Accessible group label. Keep visible option labels self-explanatory.
        showLabels - Whether to render option labels visibly. Icon-only controls keep sr-only labels.
        fullWidth - Whether the control and options should fill the available width.
        size    - 'sm' (default) or 'md'.

    Usage:
        <x-ui.segmented-control
            :options="[
                ['value' => 'both', 'label' => __('Both')],
                ['value' => 'dial', 'label' => __('Dial')],
                ['value' => 'strip', 'label' => __('Strip')],
            ]"
            value="both"
            :label="__('Display mode')"
        />
--}}
@props([
    'options' => [],
    'value' => null,
    'label' => null,
    'showLabels' => true,
    'fullWidth' => false,
    'size' => 'sm',
])

@php
    $items = collect($options)
        ->map(static fn (array $option): array => [
            'value' => (string) ($option['value'] ?? $option['id'] ?? ''),
            'label' => $option['label'] ?? ($option['value'] ?? $option['id'] ?? ''),
            'icon' => $option['icon'] ?? null,
            'disabled' => (bool) ($option['disabled'] ?? false),
        ])
        ->filter(static fn (array $option): bool => $option['value'] !== '' && filled($option['label']))
        ->values();

    $initialValue = (string) ($value ?? ($items->first()['value'] ?? ''));

    $sizeClasses = match ($size) {
        'md' => [
            'button' => 'px-input-x py-input-y text-sm leading-5',
            'icon' => 'h-4 w-4',
        ],
        default => [
            'button' => 'px-2.5 py-0.5 text-[11px] leading-4',
            'icon' => 'h-3.5 w-3.5',
        ],
    };
@endphp

<div
    x-data="{ value: @js($initialValue) }"
    x-modelable="value"
    {{ $attributes->class([
        'items-center rounded-full border border-border-default bg-surface-subtle p-0.5 shadow-inner',
        'flex w-full' => $fullWidth,
        'inline-flex' => ! $fullWidth,
    ]) }}
    role="group"
    @if ($label) aria-label="{{ $label }}" title="{{ $label }}" @endif
>
    @foreach ($items as $item)
        <button
            type="button"
            @click="
                value = @js($item['value']);
                $dispatch('segmented-control-change', { value });
            "
            :aria-pressed="value === @js($item['value'])"
            @class([
                $sizeClasses['button'],
                'inline-flex items-center justify-center gap-1 rounded-full font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50',
                'flex-1' => $fullWidth,
            ])
            :class="value === @js($item['value']) ? 'bg-surface-card text-ink shadow-sm' : 'text-muted hover:text-ink'"
            @disabled($item['disabled'])
        >
            @if ($item['icon'])
                <x-icon :name="$item['icon']" class="{{ $sizeClasses['icon'] }}" />
            @endif

            <span @class(['sr-only' => ! $showLabels && $item['icon']])>
                {{ $item['label'] }}
            </span>
        </button>
    @endforeach
</div>
