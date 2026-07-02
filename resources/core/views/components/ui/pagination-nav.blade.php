{{--
    Shared pagination page-nav (prev / numbers / next). No summary, no outer
    <nav>; included by `pagination-links` and rendered standalone by
    `x-ui.pagination` via `$paginator->links('ui.pagination-nav')`. Receives
    `$paginator` and `$elements` (page numbers and "…" separators) from
    Laravel's paginator renderer.

    Height matches the `x-ui.select` per-page control (py-input-y + text-sm) so
    the strip and the dropdown read as one coherent control row; no drop shadow
    (border-only, like the select) per DESIGN.md "subtle depth".
--}}
@if ($paginator->hasPages())
    <span class="inline-flex rtl:flex-row-reverse rounded-2xl">
    {{-- Previous --}}
    @if ($paginator->onFirstPage())
        <span aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
            <span class="inline-flex items-center px-1.5 py-input-y text-sm font-medium text-muted bg-surface-card border border-border-default cursor-not-allowed rounded-l-2xl leading-5" aria-hidden="true">
                <x-icon name="heroicon-m-chevron-left" class="w-5 h-5" />
            </span>
        </span>
    @else
        <a href="{{ url($paginator->previousPageUrl()) }}" wire:click.prevent="previousPage('{{ $paginator->getPageName() }}')" rel="prev" class="inline-flex items-center px-1.5 py-input-y text-sm font-medium text-muted bg-surface-card border border-border-default rounded-l-2xl leading-5 hover:text-ink hover:bg-surface-subtle transition ease-in-out duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-card active:bg-surface-subtle active:text-ink" aria-label="{{ __('pagination.previous') }}">
            <x-icon name="heroicon-m-chevron-left" class="w-5 h-5" />
        </a>
    @endif

    {{-- Page numbers and separators --}}
    @foreach ($elements as $element)
        @if (is_string($element))
            <span aria-disabled="true">
                <span class="inline-flex items-center justify-center min-w-[2rem] px-3 py-input-y -ml-px text-sm font-medium text-muted bg-surface-card border border-border-default cursor-default leading-5 tabular-nums">{{ $element }}</span>
            </span>
        @endif

        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span aria-current="page">
                        <span class="inline-flex items-center justify-center min-w-[2rem] px-3 py-input-y -ml-px text-sm font-medium text-accent-on bg-accent border border-accent cursor-default leading-5 tabular-nums">{{ $page }}</span>
                    </span>
                @else
                    <a href="{{ url($url) }}" wire:click.prevent="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" class="inline-flex items-center justify-center min-w-[2rem] px-3 py-input-y -ml-px text-sm font-medium text-ink bg-surface-card border border-border-default leading-5 tabular-nums hover:text-ink hover:bg-surface-subtle transition ease-in-out duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-card active:bg-surface-subtle active:text-ink" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                        {{ $page }}
                    </a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next --}}
    @if ($paginator->hasMorePages())
        <a href="{{ url($paginator->nextPageUrl()) }}" wire:click.prevent="nextPage('{{ $paginator->getPageName() }}')" rel="next" class="inline-flex items-center px-1.5 py-input-y -ml-px text-sm font-medium text-muted bg-surface-card border border-border-default rounded-r-2xl leading-5 hover:text-ink hover:bg-surface-subtle transition ease-in-out duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface-card active:bg-surface-subtle active:text-ink" aria-label="{{ __('pagination.next') }}">
            <x-icon name="heroicon-m-chevron-right" class="w-5 h-5" />
        </a>
    @else
        <span aria-disabled="true" aria-label="{{ __('pagination.next') }}">
            <span class="inline-flex items-center px-1.5 py-input-y -ml-px text-sm font-medium text-muted bg-surface-card border border-border-default cursor-not-allowed rounded-r-2xl leading-5" aria-hidden="true">
                <x-icon name="heroicon-m-chevron-right" class="w-5 h-5" />
            </span>
        </span>
    @endif
</span>
@endif
