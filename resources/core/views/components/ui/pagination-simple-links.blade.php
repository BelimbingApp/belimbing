{{--
    BLB simple pagination chrome (prev/next only, no page numbers).
    Registered as `Paginator::simpleView` for cursor/simple paginators.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-muted tabular-nums">
            @if ($paginator->currentPage() > 1)
                {{ __('Page :page', ['page' => $paginator->currentPage()]) }}
            @else
                {{ __('First page') }}
            @endif
        </p>

        <span class="inline-flex rtl:flex-row-reverse shadow-sm rounded-2xl">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <span class="inline-flex items-center px-2.5 py-input-y text-sm font-medium text-muted bg-surface-card border border-border-default cursor-not-allowed rounded-l-2xl leading-5" aria-hidden="true">
                        <x-icon name="heroicon-m-chevron-left" class="w-4 h-4" />
                        {!! __('pagination.previous') !!}
                    </span>
                </span>
            @else
                <a href="{{ url($paginator->previousPageUrl()) }}" wire:click.prevent="previousPage('{{ $paginator->getPageName() }}')" rel="prev" class="inline-flex items-center gap-1 px-2.5 py-input-y text-sm font-medium text-ink bg-surface-card border border-border-default rounded-l-2xl leading-5 hover:bg-surface-subtle transition ease-in-out duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-card active:bg-surface-subtle active:text-ink" aria-label="{{ __('pagination.previous') }}">
                    <x-icon name="heroicon-m-chevron-left" class="w-4 h-4" />
                    {!! __('pagination.previous') !!}
                </a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ url($paginator->nextPageUrl()) }}" wire:click.prevent="nextPage('{{ $paginator->getPageName() }}')" rel="next" class="inline-flex items-center gap-1 px-2.5 py-1 -ml-px text-sm font-medium text-ink bg-surface-card border border-border-default rounded-r-2xl leading-5 hover:bg-surface-subtle transition ease-in-out duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-card active:bg-surface-subtle active:text-ink" aria-label="{{ __('pagination.next') }}">
                    {!! __('pagination.next') !!}
                    <x-icon name="heroicon-m-chevron-right" class="w-4 h-4" />
                </a>
            @else
                <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 -ml-px text-sm font-medium text-muted bg-surface-card border border-border-default cursor-not-allowed rounded-r-2xl leading-5" aria-hidden="true">
                        {!! __('pagination.next') !!}
                        <x-icon name="heroicon-m-chevron-right" class="w-4 h-4" />
                    </span>
                </span>
            @endif
        </span>
    </nav>
@endif
