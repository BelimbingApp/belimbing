{{-- Shared lazy placeholder for full-page components whose render does
     expensive work (nested git scans, remote checks). The shell (menu, bars)
     renders normally; this skeleton holds the content column until the real
     component streams in. Mirrors page-header + table proportions so the
     page does not jump. --}}
<div class="space-y-section-gap" aria-busy="true">
    <div class="animate-pulse space-y-section-gap motion-reduce:animate-none" aria-hidden="true">
        <div class="space-y-2">
            <div class="h-6 w-56 rounded bg-surface-subtle/70"></div>
            <div class="h-3.5 w-80 max-w-full rounded bg-surface-subtle/50"></div>
        </div>

        <div class="rounded-2xl border border-border-default bg-surface-card p-card-inner shadow-sm">
            <div class="space-y-2.5">
                <div class="h-3.5 w-1/3 rounded bg-surface-subtle/60"></div>
                @foreach ([11, 12, 10, 12, 11] as $twelfths)
                    <div class="h-3.5 rounded bg-surface-subtle/40" style="width: {{ $twelfths }}%; min-width: {{ $twelfths * 7 }}%"></div>
                @endforeach
            </div>
        </div>
    </div>

    <p class="sr-only" role="status">{{ __('Loading page…') }}</p>
</div>
