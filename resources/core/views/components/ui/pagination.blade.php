@props([
    'paginator',
    'perPageOptions' => [],   // list<int>; empty hides the per-page selector
    'perPage' => null,        // current page size; marks the selected <option>
    'summary' => true,         // render the "Showing X–Y of N" line
    'id' => 'per-page',        // stable control id for the per-page select
])

@php
    $hasSelector = ! empty($perPageOptions);
    $hasPages = $paginator->hasPages();
    // Render the page-nav (prev/numbers/next) through Laravel's paginator so it
    // populates `$elements` internally — `elements()` is protected and cannot
    // be called directly from a Blade view.
    $navHtml = $hasPages ? $paginator->links('ui.pagination-nav') : '';
@endphp

@if ($hasPages || $hasSelector)
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            @if ($summary && $hasPages)
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
            @endif

            @if ($hasSelector)
                <span class="flex items-center gap-2">
                    <label for="{{ $id }}" class="text-sm text-muted whitespace-nowrap">{{ __('Rows per page') }}</label>
                    <x-ui.select :block="false" :id="$id" wire:model.live="perPage">
                        @foreach ($perPageOptions as $option)
                            <option value="{{ $option }}" @if ((string) $option === (string) $perPage) selected @endif>{{ $option }}</option>
                        @endforeach
                    </x-ui.select>
                </span>
            @endif
        </div>

        {!! $navHtml !!}
    </nav>
@endif
