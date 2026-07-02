{{--
    BLB pagination chrome. Registered as `Paginator::defaultView` so every
    `->links()` call renders this. Pure-presentational: no Livewire coupling,
    no per-page selector (that lives in `x-ui.pagination`).

    Receives `$paginator` and `$elements` (page numbers and "…" separators)
    from Laravel's paginator, plus any caller `$data` (e.g. `scrollTo`).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted tabular-nums">
            @if ($paginator->firstItem())
                {{ __('Showing :first to :last of :total results', [
                    'first' => $paginator->firstItem(),
                    'last' => $paginator->lastItem(),
                    'total' => $paginator->total(),
                ]) }}
            @else
                {{ __('Showing :count of :total results', [
                    'count' => $paginator->count(),
                    'total' => $paginator->total(),
                ]) }}
            @endif
        </p>

        @include('ui.pagination-nav', ['paginator' => $paginator, 'elements' => $elements])
    </nav>
@endif
