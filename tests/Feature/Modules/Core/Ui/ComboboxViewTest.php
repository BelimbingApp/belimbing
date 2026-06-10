<?php

use Illuminate\Support\Facades\Blade;

it('renders an escape path that restores the pre-edit value', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.combobox
    label="Currency"
    :options="[
        ['value' => 'USD', 'label' => 'Dollar (USD)'],
        ['value' => 'MYR', 'label' => 'Ringgit (MYR)'],
    ]"
/>
BLADE
    ));

    expect($html)
        ->toContain('editingValue: null')
        ->toContain('pendingClear: false')
        ->toContain('this.closeList(false)')
        ->toContain('this.selectedValue = this.editingValue')
        ->toContain('this.pendingClear')
        ->toContain('this.selectedValue !== this.editingValue');
});

it('keeps the pre-edit snapshot when the clear button is used', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.combobox
    label="Currency"
    :options="[
        ['value' => 'USD', 'label' => 'Dollar (USD)'],
        ['value' => 'MYR', 'label' => 'Ringgit (MYR)'],
    ]"
/>
BLADE
    ));

    expect($html)
        ->toContain('clear() {')
        ->toContain('this.pendingClear = true')
        ->toContain('this.selectedValue = null')
        ->not->toContain('this.editingValue = null');
});

it('renders a top-origin blur close transition for the dropdown', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.combobox
    label="Currency"
    :options="[
        ['value' => 'USD', 'label' => 'Dollar (USD)'],
        ['value' => 'MYR', 'label' => 'Ringgit (MYR)'],
    ]"
/>
BLADE
    ));

    expect($html)
        ->toContain('@click.outside="if (open || pendingClear) closeList()"')
        ->toContain('@focusout="requestAnimationFrame(() => { if ((open || pendingClear) && !$el.contains(document.activeElement)) closeList() })"')
        ->toContain('x-transition:leave-end="opacity-0 -translate-y-1 scale-y-95"')
        ->toContain('origin-top');
});