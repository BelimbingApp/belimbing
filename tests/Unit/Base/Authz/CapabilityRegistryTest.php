<?php

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityKey;
use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Exceptions\UnknownCapabilityException;
use Tests\TestCase;

uses(TestCase::class);

it('validates capability key grammar', function (): void {
    expect(CapabilityKey::isValid('admin.user.view'))->toBeTrue();
    expect(CapabilityKey::isValid('admin.system.database-table.list'))->toBeTrue();
    expect(CapabilityKey::isValid('Core.User.View'))->toBeFalse();
    expect(CapabilityKey::isValid('core.user'))->toBeFalse();
});

it('builds registry from configured catalog', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');

    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect($registry->has('admin.user.view'))->toBeTrue();
    expect($registry->forDomain('admin'))->toContain('admin.company.view');
});

it('throws for unknown capability', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');

    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect(fn () => $registry->assertKnown('core.user.manage'))
        ->toThrow(UnknownCapabilityException::class);
});

it('prunes a malformed capability instead of failing the whole catalog', function (): void {
    // One module ships a typo'd verb (as happened with the Data Share
    // "receive" capability); every other module's capabilities - and the
    // request that resolves this catalog to wire the Gate - must survive it.
    $catalog = new CapabilityCatalog(
        domains: ['admin'],
        verbs: ['view'],
        capabilities: ['admin.user.view', 'admin.thing.receive', 'not-a-capability', 'unknown-domain.thing.view'],
    );

    $catalog->validate();

    expect($catalog->capabilities())->toBe(['admin.user.view'])
        ->and($catalog->rejected())->toHaveKeys(['admin.thing.receive', 'not-a-capability', 'unknown-domain.thing.view'])
        ->and($catalog->rejected()['admin.thing.receive'])->toContain('unknown verb');

    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect($registry->has('admin.user.view'))->toBeTrue()
        ->and($registry->has('admin.thing.receive'))->toBeFalse();
});
