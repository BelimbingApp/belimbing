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
    // A near-miss host is a different service. Taking its owner and then
    // asking api.github.com about it would offer a stranger's public
    // repositories as installable code (#944 review).
    'a host that merely starts with github.' => ['https://github.internal.example/UnrelatedOwner/platform.git', null],
    'an enterprise host over ssh' => ['git@github.enterprise.local:Other/repo.git', null],
    'github.com with a port is still github.com' => ['https://github.com:443/BelimbingApp/belimbing.git', 'BelimbingApp'],
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

it('reports a failed lookup as incomplete, not as an empty catalog', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response('rate limited', 403)]);

    $catalog = domainCatalog();

    expect($catalog->entries())->toBe([])
        ->and($catalog->complete())->toBeFalse()
        ->and($catalog->problems())->toBe(['FixtureOrg: GitHub answered HTTP 403']);
});

it('reports an owner that publishes no Domain as complete and empty', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response([])]);

    $catalog = domainCatalog();

    expect($catalog->entries())->toBe([])
        ->and($catalog->complete())->toBeTrue()
        ->and($catalog->problems())->toBe([]);
});

it('does not let one owner answering vouch for another that failed', function (): void {
    config(['domains.owners' => ['ForkOrg', 'UpstreamOrg']]);
    Http::fake([
        'https://api.github.com/orgs/ForkOrg/repos*' => Http::response('unavailable', 503),
        'https://api.github.com/orgs/UpstreamOrg/repos*' => Http::response([domainRepo('blb-people')]),
    ]);

    $catalog = domainCatalog();

    // The upstream Domain is still offered, but the screen must not read as a
    // settled answer while the owner with precedence went unasked (#944 review).
    expect($catalog->entries())->toHaveKey('People')
        ->and($catalog->complete())->toBeFalse()
        ->and($catalog->problems())->toBe(['ForkOrg: GitHub answered HTTP 503']);
});

it('lists a personal account, which does not answer on the organisation endpoint', function (): void {
    config(['domains.owners' => ['kiatng']]);
    Http::fake([
        'https://api.github.com/orgs/kiatng/repos*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/users/kiatng/repos*' => Http::response([domainRepo('blb-people')]),
    ]);

    $catalog = domainCatalog();

    // A user-owned fork must find its own Domains rather than silently
    // showing upstream's (#944 review).
    expect(array_keys($catalog->entries()))->toBe(['People'])
        ->and($catalog->complete())->toBeTrue();
});

it('separates an owner that does not exist from an owner that could not be asked', function (): void {
    config(['domains.owners' => ['GhostOrg']]);
    Http::fake([
        'https://api.github.com/orgs/GhostOrg/repos*' => Http::response(['message' => 'Not Found'], 404),
        'https://api.github.com/users/GhostOrg/repos*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $catalog = domainCatalog();

    expect($catalog->entries())->toBe([])
        ->and($catalog->complete())->toBeFalse()
        ->and($catalog->problems())->toBe(['GhostOrg: no such GitHub organisation or user']);
});

it('follows pagination, so a Domain published on a later page is not lost', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);

    $firstPage = [];
    for ($i = 0; $i < 100; $i++) {
        $firstPage[] = domainRepo('unrelated-'.$i, ['topics' => []]);
    }

    // Matched by inspecting the request rather than by URL pattern: the page
    // marker lives in the query string, which pattern matching does not see.
    Http::fake(fn ($request) => str_contains($request->url(), 'page=2')
        ? Http::response([domainRepo('blb-people')])
        : Http::response($firstPage, 200, [
            'Link' => '<https://api.github.com/orgs/FixtureOrg/repos?page=2>; rel="next", <https://api.github.com/orgs/FixtureOrg/repos?page=2>; rel="last"',
        ]));

    $catalog = domainCatalog();

    // per_page is a page size, not an inventory: stopping at page one would
    // omit this Domain and cache the omission for a day (#944 review).
    expect(array_keys($catalog->entries()))->toBe(['People'])
        ->and($catalog->complete())->toBeTrue();
    Http::assertSentCount(2);
});

it('asks GitHub once and serves the cache afterwards', function (): void {
    config(['domains.owners' => ['FixtureOrg']]);
    Http::fake(['https://api.github.com/orgs/FixtureOrg/repos*' => Http::response([domainRepo('blb-people')])]);

    $catalog = domainCatalog();
    $catalog->entries();
    $catalog->entries();
    $catalog->complete();

    Http::assertSentCount(1);
});

it('falls back to this checkout own remotes when no owner is configured', function (): void {
    config(['domains.owners' => []]);

    // The platform checkout's origin is the canonical repository, so the
    // owner resolves without anything being written down.
    expect(domainCatalog()->owners())->toContain('BelimbingApp');
});
