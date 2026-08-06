<?php

use App\Base\Foundation\Providers\ProviderRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('resolves providers in the four-root application order', function (): void {
    $resolved = ProviderRegistry::resolve();
    $ranks = array_map(static fn (string $provider): int => match (true) {
        str_starts_with($provider, 'App\\Base\\') => 0,
        str_starts_with($provider, 'App\\Core\\') => 1,
        str_starts_with($provider, 'App\\Domains\\') => 2,
        str_starts_with($provider, 'App\\Extensions\\') => 3,
    }, $resolved);

    $sortedRanks = $ranks;
    sort($sortedRanks);

    expect($ranks)->toBe($sortedRanks)
        ->and($ranks)->toContain(0, 1);
});

it('discovers every provider from an owning component, with no app-level escape hatch', function (): void {
    $orphans = array_values(array_filter(
        ProviderRegistry::resolve(),
        static fn (string $provider): bool => ! str_starts_with($provider, 'App\\Base\\')
            && ! str_starts_with($provider, 'App\\Core\\')
            && ! str_starts_with($provider, 'App\\Domains\\')
            && ! str_starts_with($provider, 'App\\Extensions\\'),
    ));

    expect($orphans)->toBeEmpty();
});
