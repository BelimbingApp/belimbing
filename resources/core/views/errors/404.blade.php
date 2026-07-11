@php
    $notFoundTitle = __('Page not found');
    $notFoundMessage = __('This address does not match anything here. The page may have moved, or the link is out of date.');
@endphp

{{--
    Signed-in users see the 404 inside the app shell so they can navigate away;
    guests get the self-contained standalone shell. The guest branch is an
    @include (not @extends) on purpose: @extends compiles to an unconditional
    footer that would render the standalone layout even for signed-in users,
    painting a second, empty error card over the app-shell one.
--}}
@if (auth()->check())
    <x-layouts.app :title="$notFoundTitle">
        <p class="text-[11px] uppercase tracking-wider font-semibold text-muted tabular-nums mb-1">404</p>
        <x-ui.page-header
            :title="$notFoundTitle"
            :subtitle="$notFoundMessage"
            :pinnable="false"
        />
    </x-layouts.app>
@else
    @include('errors.404-guest', [
        'notFoundTitle' => $notFoundTitle,
        'notFoundMessage' => $notFoundMessage,
    ])
@endif
