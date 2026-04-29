<?php

use Illuminate\Support\Facades\Blade;

it('shows fallback diagnostics only to users who can access the control plane', function (): void {
    $fallbackAttempts = [[
        'provider' => 'anthropic',
        'model' => 'claude-opus-4',
        'error' => 'Public fallback failure',
        'diagnostic' => 'Sensitive diagnostic context',
    ]];

    $unauthorizedHtml = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" run-id="run-12345678" :fallback-attempts="$fallbackAttempts" />',
        [
            'timestamp' => now(),
            'fallbackAttempts' => $fallbackAttempts,
        ]
    ));

    expect($unauthorizedHtml)
        ->toContain('Fallbacks')
        ->toContain('Public fallback failure')
        ->not->toContain('Sensitive diagnostic context');

    $this->actingAs(createAdminUser());

    $authorizedHtml = html_entity_decode(Blade::render(
        '<x-ai.message-meta :timestamp="$timestamp" provider="openai" model="gpt-5" run-id="run-12345678" :fallback-attempts="$fallbackAttempts" />',
        [
            'timestamp' => now(),
            'fallbackAttempts' => $fallbackAttempts,
        ]
    ));

    expect($authorizedHtml)
        ->toContain('Sensitive diagnostic context')
        ->not->toContain('Public fallback failure');
});

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
