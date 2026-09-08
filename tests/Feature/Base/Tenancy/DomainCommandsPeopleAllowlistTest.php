<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('no longer allowlists People Domain console commands after blb-people#347', function (): void {
    $allowlist = array_keys((array) config('domain_commands.tenant_scope.allowlist', []));

    expect($allowlist)->not->toBeEmpty(); // connector exemptions remain until #249 lands

    foreach ($allowlist as $command) {
        expect($command)
            ->not->toStartWith('people:')
            ->and($command)->not->toStartWith('blb:attendance:')
            ->and($command)->not->toStartWith('blb:claim:')
            ->and($command)->not->toStartWith('blb:leave:')
            ->and($command)->not->toStartWith('blb:payroll:');
    }
});
