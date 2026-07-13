<?php

use Tests\Support\GeonamesSeeder;

beforeEach(function (): void {
    $this->actingAs(createAdminUser());
});

it('uses the same vertical padding as the per-page select on page pills', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();

    // Page pills and the per-page select both use py-input-y + text-sm.
    expect($html)
        ->toContain('px-3 py-input-y -ml-px text-sm font-medium text-ink')        // a number pill
        ->toContain('px-3 py-input-y -ml-px text-sm font-medium text-accent-on') // the active pill
        ->not->toContain('px-3 py-1 -ml-px text-xs');                             // old short pill sizing gone
});

it('gives number and separator pills a uniform min width with tabular numerals', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();

    expect($html)
        ->toContain('min-w-[2rem] px-3 py-input-y -ml-px text-sm font-medium text-ink')
        ->toContain('tabular-nums');
});

it('sizes chevron icons to match the page-number line height so prev/next pills are not shorter', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();
    preg_match('/<nav role="navigation" aria-label="Pagination Navigation".*?<\/nav>/s', $html, $m);
    $nav = $m[0] ?? '';

    // The page-number pills use leading-5 (20px line box); the chevron SVGs must
    // be w-5 h-5 (20px) too, or the prev/next pills render shorter than the
    // number pills. The icon name resolves to path data, so assert on the SVG.
    expect($nav)
        ->toContain('<svg class="w-5 h-5"')          // prev chevron
        ->not->toContain('<svg class="w-4 h-4"');     // old, too-short chevron
});
