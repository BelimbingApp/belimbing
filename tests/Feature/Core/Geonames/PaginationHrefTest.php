<?php

use Tests\Support\GeonamesSeeder;

beforeEach(function (): void {
    $this->actingAs(createAdminUser());
});

it('renders absolute page-2 hrefs and wire:click for the countries list', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();

    preg_match_all('/href="([^"]*page=2[^"]*)"/', $html, $h);

    expect($h[1])->not->toBeEmpty();
    foreach ($h[1] as $href) {
        expect($href)->toStartWith('http')
            ->and($href)->toContain('/admin/geonames/countries?page=2');
    }
    expect($html)->toContain('wire:click.prevent="gotoPage(2,');
});

it('renders absolute page-2 hrefs and wire:click for the admin1 list', function (): void {
    GeonamesSeeder::admin1(25);

    $html = $this->get(route('admin.geonames.admin1.index'))->assertOk()->getContent();

    preg_match_all('/href="([^"]*page=2[^"]*)"/', $html, $h);

    expect($h[1])->not->toBeEmpty();
    foreach ($h[1] as $href) {
        expect($href)->toStartWith('http')
            ->and($href)->toContain('/admin/geonames/admin1?page=2');
    }
    expect($html)->toContain('wire:click.prevent="gotoPage(2,');
});

it('renders absolute page-2 hrefs and wire:click for the postcodes list', function (): void {
    GeonamesSeeder::postcodes(25);

    $html = $this->get(route('admin.geonames.postcodes.index'))->assertOk()->getContent();

    preg_match_all('/href="([^"]*page=2[^"]*)"/', $html, $h);

    expect($h[1])->not->toBeEmpty();
    foreach ($h[1] as $href) {
        expect($href)->toStartWith('http')
            ->and($href)->toContain('/admin/geonames/postcodes?page=2');
    }
    expect($html)->toContain('wire:click.prevent="gotoPage(2,');
});

it('does not render a relative page-2 href that would double the path', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();

    expect($html)->not->toContain('href="admin/geonames/countries?page=2')
        ->and($html)->not->toContain('href="admin/geonames/admin/geonames/countries');
});

it('renders the per-page selector without a 10 option, with a 300 option, reacting live', function (): void {
    GeonamesSeeder::countries(25);

    $html = $this->get(route('admin.geonames.countries.index'))->assertOk()->getContent();

    expect($html)
        ->toContain('<option value="300"')
        ->toContain('<option value="20"')
        ->toContain('<option value="50"')
        ->toContain('<option value="100"')
        ->not->toContain('<option value="10"')
        // Livewire 3 `wire:model` is deferred; the selector must use `.live`
        // or changing it never sends a round-trip.
        ->toContain('wire:model.live="perPage"');
});
