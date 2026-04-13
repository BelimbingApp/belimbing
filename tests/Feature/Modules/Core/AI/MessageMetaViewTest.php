<?php

use Illuminate\Support\Facades\Blade;

it('renders the run detail popup as an alpine popover instead of a native dialog', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" run-id="run-12345678" />',
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('x-show="popoverOpen"')
        ->toContain('@click.outside="popoverOpen = false"')
        ->toContain('role="dialog"')
        ->not->toContain('<dialog')
        ->not->toContain('$el.show();')
        ->not->toContain('$el.close();')
        ->not->toContain('open:flex');
});

it('renders the run duration between the timestamp and provider label', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" :latency-ms="90000" run-id="run-12345678" />',
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('1 min 30 sec')
        ->toContain('openai/gpt-5');
});
