<?php

use Illuminate\Support\Facades\Blade;

it('renders the run detail popup as a native dialog with alpine popover behavior', function (): void {
    $html = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" run-id="run-12345678" />',
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('x-show="popoverOpen"')
        ->toContain('@click.outside="popoverOpen = false"')
        ->toContain('<dialog')
        ->toContain(':open="popoverOpen"')
        ->toContain('@close="popoverOpen = false"')
        ->toContain('run-12345678')
        ->not->toContain('$el.show();')
        ->not->toContain('$el.close();')
        ->not->toContain('open:flex')
        ->not->toContain('View in Control Plane');
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

it('renders token breakdown and copy action in the run popup', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ai.message-meta
    :timestamp="$timestamp"
    provider="openai"
    model="gpt-5"
    run-id="run-12345678"
    :tokens="['input' => 1200, 'cached' => 300, 'output' => 45, 'total' => 1245]"
/>
BLADE,
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('Copy run ID')
        ->toContain('Input Tokens')
        ->toContain('1,200')
        ->toContain('Cached Tokens')
        ->toContain('300')
        ->toContain('Output Tokens')
        ->toContain('45')
        ->toContain('Total Tokens')
        ->toContain('1,245');
});

it('shows completed tool-round and tool-call totals without calling rounds tools', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ai.message-meta
    :timestamp="$timestamp"
    run-id="run-12345678"
    :tool-round-count="18"
    :tool-call-count="31"
/>
BLADE,
        ['timestamp' => now()]
    ));

    expect($html)
        ->toContain('Completed in 18 tool rounds')
        ->toContain('31 tool calls')
        ->not->toContain('18 tools');
});
