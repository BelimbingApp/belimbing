<?php

use App\Base\Database\Services\Backup\RetentionPolicy;

const RETENTION_POLICY_MANIFEST_SUFFIX = '.manifest.json';

function rpEntry(int $finishedAtUnix, string $id = ''): array
{
    $id = $id !== '' ? $id : 'b-'.$finishedAtUnix;

    return [
        'manifest_path' => "p/{$id}".RETENTION_POLICY_MANIFEST_SUFFIX,
        'artifact_path' => "p/{$id}.bak",
        'finished_at_unix' => $finishedAtUnix,
    ];
}

function rpIds(array $entries): array
{
    return array_map(
        fn ($e) => substr(basename($e['manifest_path']), 0, -strlen(RETENTION_POLICY_MANIFEST_SUFFIX)),
        $entries
    );
}

it('returns nothing when keep_days is zero', function (): void {
    $policy = new RetentionPolicy(keepDays: 0, keepCount: 0);
    $now = 1_700_000_000;

    $entries = [
        rpEntry($now - 86400 * 100),
        rpEntry($now - 86400 * 200),
    ];

    expect($policy->selectExpired($entries, $now))->toBe([]);
});

it('drops only entries older than keep_days', function (): void {
    $policy = new RetentionPolicy(keepDays: 30, keepCount: 0);
    $now = 1_700_000_000;

    $entries = [
        rpEntry($now - 86400 * 1, 'recent'),
        rpEntry($now - 86400 * 31, 'over-30'),
        rpEntry($now - 86400 * 100, 'over-100'),
    ];

    $expired = $policy->selectExpired($entries, $now);

    expect(rpIds($expired))->toBe(['over-30', 'over-100']);
});

it('always preserves keep_count newest regardless of age', function (): void {
    $policy = new RetentionPolicy(keepDays: 1, keepCount: 3);
    $now = 1_700_000_000;

    // All 5 entries are older than 1 day; keep_count=3 must protect the newest 3.
    $entries = [
        rpEntry($now - 86400 * 2, 'a'),
        rpEntry($now - 86400 * 3, 'b'),
        rpEntry($now - 86400 * 4, 'c'),
        rpEntry($now - 86400 * 5, 'd'),
        rpEntry($now - 86400 * 6, 'e'),
    ];

    $expired = $policy->selectExpired($entries, $now);
    expect(rpIds($expired))->toBe(['d', 'e']);
});

it('skips entries with unknown finished_at', function (): void {
    $policy = new RetentionPolicy(keepDays: 30, keepCount: 0);
    $now = 1_700_000_000;

    $entries = [
        rpEntry(0, 'unknown'),
        rpEntry($now - 86400 * 100, 'old'),
    ];

    $expired = $policy->selectExpired($entries, $now);
    expect(rpIds($expired))->toBe(['old']);
});
