@props([
    'variant' => 'default',
    'tooltip' => null,
])

@php
$variantClasses = match($variant) {
    'success' => 'bg-status-success-subtle text-status-success',
    'danger' => 'bg-status-danger-subtle text-status-danger',
    'warning' => 'bg-status-warning-subtle text-status-warning',
    'info' => 'bg-status-info-subtle text-status-info',
    'accent' => 'bg-accent/10 text-accent',
    default => 'bg-surface-subtle text-ink',
};

$badgeClasses = "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {$variantClasses}";
$tooltipId = filled($tooltip) ? 'badge-tooltip-'.\Illuminate\Support\Str::lower((string) \Illuminate\Support\Str::ulid()) : null;
@endphp

@if (filled($tooltip))
    <span
        x-data="{
            open: false,
            left: 0,
            top: 0,
            position() {
                const trigger = this.$refs.trigger.getBoundingClientRect();
                const tooltip = this.$refs.tooltip;
                const margin = 8;
                const gap = 4;
                const width = tooltip.offsetWidth;
                const height = tooltip.offsetHeight;
                const centeredLeft = trigger.left + (trigger.width / 2) - (width / 2);
                const belowTop = trigger.bottom + gap;
                const aboveTop = trigger.top - height - gap;

                this.left = Math.min(Math.max(centeredLeft, margin), window.innerWidth - width - margin);
                this.top = belowTop + height + margin <= window.innerHeight ? belowTop : Math.max(margin, aboveTop);
            },
            show() {
                this.open = true;
                this.$nextTick(() => this.position());
            },
        }"
        class="relative inline-flex"
        @keydown.escape.window="open = false"
        @resize.window="open && position()"
        @scroll.window="open && position()"
    >
        <span
            x-ref="trigger"
            tabindex="0"
            @mouseenter="show()"
            @mouseleave="open = false"
            @focus="show()"
            @blur="open = false"
            aria-describedby="{{ $tooltipId }}"
            class="inline-flex rounded-full outline-none focus-visible:ring-2 focus-visible:ring-accent/40 focus-visible:ring-offset-0"
        >
            <span {{ $attributes->merge(['class' => $badgeClasses]) }}>
                {{ $slot }}
            </span>
        </span>

        <template x-teleport="body">
            <span
                id="{{ $tooltipId }}"
                x-ref="tooltip"
                x-show="open"
                x-cloak
                x-transition.opacity.duration.100ms
                role="tooltip"
                :style="`left: ${left}px; top: ${top}px;`"
                class="pointer-events-none fixed z-50 w-max max-w-[min(18rem,calc(100vw-1rem))] whitespace-normal rounded-lg border border-border-default bg-surface-card px-2 py-1.5 text-[11px] font-normal normal-case tracking-normal text-ink shadow-sm"
            >
                {{ $tooltip }}
            </span>
        </template>
    </span>
@else
    <span {{ $attributes->merge(['class' => $badgeClasses]) }}>
        {{ $slot }}
    </span>
@endif
