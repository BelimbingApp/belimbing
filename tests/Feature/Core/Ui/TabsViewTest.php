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
