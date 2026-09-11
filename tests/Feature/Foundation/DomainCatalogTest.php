<?php

use App\Base\Foundation\Services\DomainCatalog;
use Illuminate\Support\Facades\Http;

/**
 * Tenant isolation: not applicable — the catalog describes installable
 * software for the installation, not tenant-owned data.
 *
 * Installable Domains are discovered, not listed (#941): a repository named
 * blb-<id> carrying the blb-domain topic, in the organisation this checkout's
 * own remotes point at.
 */
function domainCatalog(): DomainCatalog
{
    $catalog = app(DomainCatalog::class);
    $catalog->forget();

    return $catalog;
}

function domainRepo(string $name, array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'topics' => [DomainCatalog::TOPIC],
        'clone_url' => 'https://github.com/FixtureOrg/'.$name.'.git',
        'description' => 'Fixture '.$name,
    ], $overrides);
}

it('derives the mount name from the repository name, and refuses names that are not Domain repositories', function (string $repo, ?string $expected): void {
    expect(DomainCatalog::mountNameFor($repo))->toBe($expected);
})->with([
    'single segment' => ['blb-people', 'People'],
    'two segments' => ['blb-people-connector', 'PeopleConnector'],
    'digits are kept' => ['blb-payroll-my2', 'PayrollMy2'],
    'the platform itself' => ['belimbing', null],
    'no prefix' => ['people', null],
    'prefix only' => ['blb-', null],
    'upper case is not an id' => ['blb-People', null],
    'trailing hyphen' => ['blb-people-', null],
]);

it('reads the owner out of a remote URL in either spelling, and skips remotes that are not GitHub', function (string $url, ?string $expected): void {
    expect(DomainCatalog::ownerFromRemoteUrl($url))->toBe($expected);
})->with([
    'ssh' => ['git@github.com:BelimbingApp/belimbing.git', 'BelimbingApp'],
    'https' => ['https://github.com/BelimbingApp/belimbing.git', 'BelimbingApp'],
    'https with a token, as actions/checkout writes it' => ['https://x-access-token:secret@github.com/BelimbingApp/belimbing', 'BelimbingApp'],
    'a self-hosted remote is not guessed at' => ['https://git.internal.example/team/repo.git', null],
    'ssh to somewhere else' => ['git@gitlab.com:team/repo.git', null],
    'empty' => ['', null],
]);

it('lists only repositories carrying the topic, keyed by mount name', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response([
        domainRepo('blb-people'),
        domainRepo('blb-people-connector'),
        // Published in the same org but not a Domain: no topic.
        domainRepo('blb-payroll-my', ['topics' => ['blb-source']]),
        // Carries the topic but is not a Domain repository name.
        domainRepo('belimbing'),
        // No topics at all.
        domainRepo('blb-commerce', ['topics' => []]),
    ])]);

    $entries = domainCatalog()->entries();

    expect(array_keys($entries))->toBe(['People', 'PeopleConnector'])
        ->and($entries['PeopleConnector']['repo'])->toBe('https://github.com/FixtureOrg/blb-people-connector.git')
        ->and($entries['People']['description'])->toBe('Fixture blb-people')
        ->and($entries['People']['owner'])->toBe('FixtureOrg');
});

it('searches origin before upstream and keeps the first owner that publishes a Domain', function (): void {
    config(['domains.owners' => ['ForkOrg', 'UpstreamOrg']]);
    Http::fake([
        'https://api.github.com/orgs/ForkOrg/repos*' => Http::response([
            domainRepo('blb-people', ['clone_url' => 'https://github.com/ForkOrg/blb-people.git']),
        ]),
        'https://api.github.com/orgs/UpstreamOrg/repos*' => Http::response([
            domainRepo('blb-people', ['clone_url' => 'https://github.com/UpstreamOrg/blb-people.git']),
            domainRepo('blb-commerce', ['clone_url' => 'https://github.com/UpstreamOrg/blb-commerce.git']),
        ]),
    ]);

    $entries = domainCatalog()->entries();

    // The fork's own People wins; Commerce it does not host comes from upstream.
    expect($entries['People']['repo'])->toBe('https://github.com/ForkOrg/blb-people.git')
        ->and($entries['Commerce']['repo'])->toBe('https://github.com/UpstreamOrg/blb-commerce.git');
});

it('reports an unreachable catalog as unreachable, not as an empty one', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response('rate limited', 403)]);

    $catalog = domainCatalog();

    expect($catalog->entries())->toBe([])
        ->and($catalog->reachable())->toBeFalse();
});

it('reports an owner that publishes no Domain as reachable and empty', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response([])]);

    $catalog = domainCatalog();

    expect($catalog->entries())->toBe([])
        ->and($catalog->reachable())->toBeTrue();
});

it('asks GitHub once and serves the cache afterwards', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response([domainRepo('blb-people')])]);

    $catalog = domainCatalog();
    $catalog->entries();
    $catalog->entries();
    $catalog->reachable();

    Http::assertSentCount(1);
});

it('falls back to this checkout own remotes when no owner is configured', function (): void {
    config(['domains.owners' => []]);

    // The platform checkout's origin is the canonical repository, so the
    // owner resolves without anything being written down.
    expect(domainCatalog()->owners())->toContain('BelimbingApp');
});
