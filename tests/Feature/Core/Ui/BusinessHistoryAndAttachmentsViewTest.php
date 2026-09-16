<?php

use Illuminate\Support\Facades\Blade;

it('renders the business history contract with module-owned row details', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-ui.business-history>
            <x-ui.business-history-row id="event-7" when="2026-09-16 08:00:00" action="Approved" by="A. Reviewer">
                <x-ui.button wire:click="viewScores(7)" variant="ghost">View scores</x-ui.button>
            </x-ui.business-history-row>
        </x-ui.business-history>
        BLADE);

    expect($html)->toContain('When')
        ->toContain('Action')
        ->toContain('By')
        ->toContain('Details')
        ->toContain('Approved')
        ->toContain('A. Reviewer')
        ->toContain('wire:click="viewScores(7)"')
        ->toContain('business-history-event-7');
});

it('renders reusable accessible file input attributes', function (): void {
    $html = Blade::render(<<<'BLADE'
        <x-ui.file-input id="decision-evidence" label="Evidence" wire:model="evidence" accept="application/pdf,image/png" multiple />
        BLADE);

    expect($html)->toContain('for="decision-evidence"')
        ->toContain('id="decision-evidence"')
        ->toContain('wire:model="evidence"')
        ->toContain('accept="application/pdf,image/png"')
        ->toContain('multiple');
});
