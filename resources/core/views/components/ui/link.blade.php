@props([
    'kind' => 'internal',
    'href' => null,
    'icon' => null,
    'navigate' => true,
])

@php
    /*
     * Canonical text-link primitive. One behavior (`kind`) maps to exactly one
     * glyph and one target/rel contract — see the link dictionary in
     * `resources/core/views/AGENTS.md` and the rendered "Links" block in
     * Administration > System > UI Reference. Callers never hand-write
     * `target`/`rel`/the affordance icon; pass a `kind` instead.
     *
     *   internal  same-tab in-app navigation (default); adds wire:navigate, no icon
     *   new-tab   forced new tab to an in-app route; box-arrow, rel=noopener
     *   anchor    in-page section link (#…); leading link glyph
     *   external  leaves BLB; box-arrow, rel=noopener noreferrer
     *   download  triggers a file download; leading down-tray glyph, download attr
     *
     * For an Alpine-bound destination pass `:href` (x-bind) and omit the `href`
     * prop. To suppress the icon on a link that wraps non-text content, pass
     * `:icon="false"`. To override the glyph, pass an explicit icon name.
     */
    $opensNewTab = in_array($kind, ['new-tab', 'external'], true);

    $rel = match ($kind) {
        'external' => 'noopener noreferrer',
        'new-tab' => 'noopener',
        default => null,
    };

    $defaultIcon = match ($kind) {
        'new-tab', 'external' => 'heroicon-o-arrow-top-right-on-square',
        'anchor' => 'heroicon-o-link',
        'download' => 'heroicon-o-arrow-down-tray',
        default => null,
    };

    $iconName = $icon === false ? null : ($icon ?? $defaultIcon);

    // Leading for "what kind of thing" (anchor, download); trailing for
    // "what happens" (new-tab, external).
    $iconLeading = in_array($kind, ['anchor', 'download'], true);

    $useNavigate = $kind === 'internal' && $navigate;
@endphp

<a
    @if ($href !== null) href="{{ $href }}" @endif
    @if ($opensNewTab) target="_blank" rel="{{ $rel }}" @endif
    @if ($kind === 'download') download @endif
    @if ($useNavigate) wire:navigate @endif
    {{ $attributes->class([
        'inline-flex items-center gap-1 rounded-sm text-accent hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2',
    ]) }}
>
    @if ($iconName && $iconLeading)
        <x-icon :name="$iconName" class="h-3.5 w-3.5 shrink-0 opacity-60" />
    @endif
    <span>{{ $slot }}</span>
    @if ($iconName && ! $iconLeading)
        <x-icon :name="$iconName" class="h-3.5 w-3.5 shrink-0 opacity-60" />
    @endif
</a>
