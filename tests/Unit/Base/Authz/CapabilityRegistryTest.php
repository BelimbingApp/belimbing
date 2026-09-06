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

    expect($catalog->rejected())->toBe([]);
    expect($registry->has('admin.user.view'))->toBeTrue();
    expect($registry->has('base.settings.user.manage'))->toBeTrue();
    expect($registry->forDomain('admin'))->toContain('admin.company.view');
});

it('retains mounted team-scoped read capabilities in the registry', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');
    $authzConfig['domains']['people'] = 'People domain';
    $authzConfig['capabilities'][] = 'people.training.passport.view-team';

    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect($catalog->verbs())->toContain('view-team')
        ->and($catalog->rejected())->not->toHaveKey('people.training.passport.view-team')
        ->and($registry->has('people.training.passport.view-team'))->toBeTrue()
        ->and($registry->forDomain('people'))->toContain('people.training.passport.view-team');
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

/*
 * Provider-port verbs (#779).
 *
 * `read` and `write` authorize a direction of flow through an external
 * provider port: whether this installation may pull records from a provider,
 * and whether it may push them back. They are not the CRUD pair. `view` is a
 * person looking at a record in the interface; `read` is a port draining a
 * provider on nobody's behalf in particular. Keeping them apart is what stops
 * "may see an employee" from quietly becoming "may siphon the employee table
 * out of the vendor system".
 */
it('registers the provider-port read and write verbs', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');
    $authzConfig['domains']['people-connector'] = 'People Connector domain';
    $authzConfig['capabilities'][] = 'people-connector.workforce-port.read';
    $authzConfig['capabilities'][] = 'people-connector.workforce-port.write';

    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $registry = CapabilityRegistry::fromCatalog($catalog);

    // Scoped to these two keys rather than asserting the whole catalog is
    // clean: a composed installation carries other modules' capabilities, and
    // this test is about these verbs, not about everything else's spelling.
    expect($catalog->verbs())->toContain('read')
        ->and($catalog->verbs())->toContain('write')
        ->and($catalog->rejected())->not->toHaveKey('people-connector.workforce-port.read')
        ->and($catalog->rejected())->not->toHaveKey('people-connector.workforce-port.write')
        ->and($registry->has('people-connector.workforce-port.read'))->toBeTrue()
        ->and($registry->has('people-connector.workforce-port.write'))->toBeTrue();
});

it('rejects a provider-port capability when its verb is not registered', function (string $verb): void {
    // Deleting the verb from the grammar must take its capability with it,
    // rather than leaving a key that looks registered and is not.
    $catalog = new CapabilityCatalog(
        domains: ['people-connector'],
        verbs: array_values(array_diff(['read', 'write'], [$verb])),
        capabilities: ["people-connector.workforce-port.{$verb}"],
    );

    $catalog->validate();

    expect($catalog->capabilities())->toBe([])
        ->and($catalog->rejected())->toHaveKey("people-connector.workforce-port.{$verb}")
        ->and($catalog->rejected()["people-connector.workforce-port.{$verb}"])->toContain("unknown verb [{$verb}]");

    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect($registry->has("people-connector.workforce-port.{$verb}"))->toBeFalse();
})->with(['read', 'write']);

it('normalises case but still fails closed on a malformed provider-port key', function (): void {
    // Registering the verbs widens the grammar by exactly two words. It does
    // not soften the grammar itself.
    //
    // Case is normalised rather than refused: the catalog lowercases domains,
    // verbs and capabilities in its constructor, so a shouted key is the same
    // key. What still fails closed is the shape — too few segments, an unknown
    // domain, or a last segment that is not a registered verb.
    $catalog = new CapabilityCatalog(
        domains: ['people-connector'],
        verbs: ['read', 'write'],
        capabilities: [
            'people-connector.workforce-port.read',
            'PEOPLE-CONNECTOR.WORKFORCE-PORT.WRITE',
            'people-connector.read',
            'people-connector.workforce-port.read.payroll',
            'unknown-domain.workforce-port.write',
        ],
    );

    $catalog->validate();

    expect($catalog->capabilities())->toBe([
        'people-connector.workforce-port.read',
        'people-connector.workforce-port.write',
    ])
        ->and($catalog->rejected())->toHaveKeys([
            'people-connector.read',
            'people-connector.workforce-port.read.payroll',
            'unknown-domain.workforce-port.write',
        ])
        // The direction has to be the last segment. A key that buries it in
        // the middle parses its tail as the verb and is dropped — which is
        // exactly what the Connector's own provider keys do today.
        ->and($catalog->rejected()['people-connector.workforce-port.read.payroll'])
        ->toContain('unknown verb [payroll]');
});
