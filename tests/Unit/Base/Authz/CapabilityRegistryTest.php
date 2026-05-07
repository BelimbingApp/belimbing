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
