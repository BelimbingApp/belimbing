<?php

use App\Base\Foundation\Enums\StatusVariant;
use Illuminate\Support\Facades\Blade;

it('renders alert live-region semantics by severity', function (): void {
    $infoHtml = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.alert variant="info">Informational context</x-ui.alert>
BLADE
    ));

    $warningHtml = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.alert variant="warning">Needs attention</x-ui.alert>
BLADE
    ));

    expect($infoHtml)
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('Informational context');

    expect($warningHtml)
        ->toContain('role="alert"')
        ->toContain('aria-live="assertive"')
        ->toContain('Needs attention');
});

it('lets static alerts opt out of announcement semantics', function (): void {
    $html = html_entity_decode(Blade::render(
        <<<'BLADE'
<x-ui.alert variant="success" :announce="false">Static status</x-ui.alert>
BLADE
    ));

    expect($html)
        ->not->toContain('role=')
        ->not->toContain('aria-live=');
});

it('rejects unknown status variants instead of rendering success', function (): void {
    StatusVariant::fromLabel('default');
})->throws(InvalidArgumentException::class, 'Unknown status variant [default].');
