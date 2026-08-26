<?php

use Illuminate\Support\Facades\Blade;

function renderStableTabsFixture(): string
{
    return html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.tabs
    tabs-id="stable-tabs"
    :tabs="[
        ['id' => 'first', 'label' => 'First'],
        ['id' => 'second', 'label' => 'Second'],
    ]"
    default="first"
>
    <x-ui.tab id="first">First panel</x-ui.tab>
    <x-ui.tab id="second">Second panel</x-ui.tab>
</x-ui.tabs>
BLADE
    ));
}

it('keeps tab identity stable across server renders', function (): void {
    $firstRender = renderStableTabsFixture();
    $secondRender = renderStableTabsFixture();

    expect($firstRender)
        ->toBe($secondRender)
        ->toContain('id="stable-tabs-tab-first"')
        ->toContain('aria-controls="stable-tabs-panel-first"')
        ->toContain('id="stable-tabs-panel-first"')
        ->toContain('aria-labelledby="stable-tabs-tab-first"')
        ->not->toContain(':id="');
});

it('renders href tabs as in-app links that keep deep routes', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.tabs
    tabs-id="href-tabs"
    :tabs="[
        ['id' => 'profile', 'label' => 'Profile', 'href' => '/settings/profile'],
        ['id' => 'password', 'label' => 'Password', 'href' => '/settings/password'],
    ]"
    default="profile"
    persistence="none"
>
    <x-ui.tab id="profile">Profile panel</x-ui.tab>
    <x-ui.tab id="password">Password panel</x-ui.tab>
</x-ui.tabs>
BLADE
    ));

    expect($html)
        ->toContain('href="/settings/profile"')
        ->toContain('href="/settings/password"')
        ->toContain('wire:navigate')
        ->toContain('id="href-tabs-tab-profile"')
        ->toContain('id="href-tabs-tab-password"')
        ->not->toContain('<button');
});
