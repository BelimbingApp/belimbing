<?php

use Illuminate\Support\Facades\Blade;

// Distinguishing SVG path fragments — <x-icon> renders inline SVG, not the name.
const BOX_ARROW = 'M13.5 6H5.25C4.00736 6';      // heroicon-o-arrow-top-right-on-square
const LINK_GLYPH = 'M13.1903 8.68842';            // heroicon-o-link
const DOWN_TRAY = 'M3 16.5V18.75C3 19.9926';      // heroicon-o-arrow-down-tray

it('renders an internal link with wire:navigate and no affordance icon', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link href="/dashboard">Dashboard</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('href="/dashboard"')
        ->toContain('wire:navigate')
        ->toContain('Dashboard')
        ->not->toContain('target="_blank"')
        ->not->toContain('<svg');
});

it('lets an internal link opt out of wire:navigate for a hard navigation', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link href="/export" :navigate="false">Reload</x-ui.link>
BLADE
    ));

    expect($html)->not->toContain('wire:navigate');
});

it('renders an external link with the box-arrow glyph and full rel', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link kind="external" href="https://example.com">Docs</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('target="_blank"')
        ->toContain('rel="noopener noreferrer"')
        ->toContain(BOX_ARROW);
});

it('renders a forced new-tab in-app link with noopener only', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link kind="new-tab" href="/shifts">Open Shifts</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('target="_blank"')
        ->toContain('rel="noopener"')
        ->not->toContain('noreferrer')
        ->toContain(BOX_ARROW);
});

it('renders an in-page anchor with the link glyph and no new tab', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link kind="anchor" href="#section-5">Section 5</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('href="#section-5"')
        ->toContain(LINK_GLYPH)
        ->not->toContain('target="_blank"')
        ->not->toContain('wire:navigate');
});

it('renders a download link with the down-tray glyph and download attribute', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link kind="download" href="/files/report.pdf">Report</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('download')
        ->toContain(DOWN_TRAY);
});

it('suppresses the icon when a link wraps non-text content', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.link kind="external" href="https://example.com" :icon="false">Thumb</x-ui.link>
BLADE
    ));

    expect($html)
        ->toContain('target="_blank"')
        ->not->toContain('<svg');
});
