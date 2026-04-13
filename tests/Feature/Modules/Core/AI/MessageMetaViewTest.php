<?php

use Illuminate\Support\Facades\Blade;

it('guards run detail dialog state transitions before toggling the popup', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" run-id="run-12345678" />',
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('if (popoverOpen) {')
        ->toContain('if (! $el.open) {')
        ->toContain('$el.show();')
        ->toContain('if ($el.open) {')
        ->toContain('$el.close();')
        ->toContain('@close="popoverOpen = false"')
        ->not->toContain('x-effect="popoverOpen ? $el.show() : $el.close()"');
});
