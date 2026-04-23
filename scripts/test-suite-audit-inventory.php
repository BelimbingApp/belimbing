#!/usr/bin/env php
<?php

declare(strict_types=1);

final class TestSuiteAuditInventoryException extends RuntimeException {}

$projectRoot = realpath(__DIR__.'/..');

if ($projectRoot === false) {
    fwrite(STDERR, "Could not resolve project root.\n");

    exit(1);
}

$testsRoot = $projectRoot.'/tests';

if (! is_dir($testsRoot)) {
    fwrite(STDERR, "Could not find tests directory.\n");

    exit(1);
}

$files = collectTestFiles($testsRoot);
$inventory = array_map(
    fn (string $path): array => analyzeTestFile($path, $projectRoot),
    $files,
);

$summary = summarizeInventory($inventory);

echo renderInventoryReport($summary, $inventory, $projectRoot);

/**
 * @return list<string>
 */
function collectTestFiles(string $testsRoot): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS),
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * @return array{
 *   path: string,
 *   suite: string,
 *   area: string,
 *   lines: int,
 *   examples: int,
 *   describe_blocks: int,
 *   mock_signals: int,
 *   http_fake: int,
 *   assert_ok: int,
 *   assert_see: int,
 *   assert_redirect: int,
 *   refresh_db: int,
 *   filesystem_signals: int,
 *   reasons: list<string>,
 *   score: int
 * }
 */
function analyzeTestFile(string $path, string $projectRoot): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new TestSuiteAuditInventoryException("Could not read {$path}");
    }

    $relativePath = ltrim(str_replace($projectRoot, '', $path), '/');
    [$suite, $area] = detectSuiteAndArea($relativePath);

    $examples = matchCount($contents, '/\b(?:it|test)\s*\(/');
    $describeBlocks = matchCount($contents, '/\bdescribe\s*\(/');
    $mockSignals = substr_count($contents, 'Mockery::mock(') + substr_count($contents, '->shouldReceive(');
    $httpFake = substr_count($contents, 'Http::fake(');
    $assertOk = substr_count($contents, 'assertOk(');
    $assertSee = substr_count($contents, 'assertSee(');
    $assertRedirect = substr_count($contents, 'assertRedirect(');
    $refreshDb = substr_count($contents, 'RefreshDatabase::class') + substr_count($contents, 'LazilyRefreshDatabase::class');
    $filesystemSignals = substr_count($contents, 'storage_path(')
        + substr_count($contents, 'File::deleteDirectory(')
        + substr_count($contents, 'storage/app/ai/workspace');

    $reasons = [];
    $score = 0;

    $redirectOnly = $assertRedirect > 0 && $assertOk === 0 && $assertSee === 0 && $mockSignals === 0 && $httpFake === 0;
    if ($redirectOnly) {
        $reasons[] = 'redirect-only';
        $score += 8;
    }

    $smokeMarkup = $assertSee >= 2 && $httpFake === 0 && $mockSignals === 0;
    if ($smokeMarkup) {
        $reasons[] = 'smoke-or-markup';
        $score += 6;
    }

    $mockHeavy = $mockSignals >= 12;
    if ($mockHeavy) {
        $reasons[] = 'mock-heavy';
        $score += 6 + min(4, intdiv($mockSignals, 20));
    }

    $happyPathHttp = $httpFake > 0
        && ! preg_match('/\b(?:400|401|403|404|409|422|429|500|502|503)\b/', $contents)
        && ! str_contains($contents, 'AiErrorType::')
        && ! str_contains($contents, 'runtime_error')
        && ! preg_match('/\b(?:toThrow|assertThrows?|Exception)\b/', $contents);
    if ($happyPathHttp) {
        $reasons[] = 'happy-path-http';
        $score += 5;
    }

    $filesystemSensitive = $filesystemSignals >= 2;
    if ($filesystemSensitive) {
        $reasons[] = 'filesystem-sensitive';
        $score += 4;
    }

    $dbHeavy = $refreshDb > 0;
    if ($dbHeavy) {
        $reasons[] = 'db-refresh';
        $score += 2;
    }

    if ($examples >= 20) {
        $reasons[] = 'large-example-count';
        $score += 2;
    }

    if ($score === 0 && $examples === 0 && $describeBlocks === 0) {
        $reasons[] = 'support-or-non-test-file';
    }

    return [
        'path' => $relativePath,
        'suite' => $suite,
        'area' => $area,
        'lines' => substr_count($contents, "\n") + 1,
        'examples' => $examples,
        'describe_blocks' => $describeBlocks,
        'mock_signals' => $mockSignals,
        'http_fake' => $httpFake,
        'assert_ok' => $assertOk,
        'assert_see' => $assertSee,
        'assert_redirect' => $assertRedirect,
        'refresh_db' => $refreshDb,
        'filesystem_signals' => $filesystemSignals,
        'reasons' => $reasons,
        'score' => $score,
    ];
}

function matchCount(string $contents, string $pattern): int
{
    return preg_match_all($pattern, $contents);
}

/**
 * @return array{0: string, 1: string}
 */
function detectSuiteAndArea(string $relativePath): array
{
    $parts = explode('/', $relativePath);
    array_shift($parts); // tests

    $suite = 'Bootstrap';
    $area = 'Bootstrap';

    if (count($parts) > 1) {
        $suite = $parts[0] ?? 'Unknown';
        $areaParts = array_slice($parts, 1);

        if (($areaParts[0] ?? null) === 'Modules') {
            $area = implode('/', array_slice($areaParts, 0, 3));
        } elseif (($areaParts[0] ?? null) === 'Base') {
            $area = implode('/', array_slice($areaParts, 0, 2));
        } else {
            $area = $areaParts[0] ?? 'root';
        }
    }

    return [$suite, $area];
}

/**
 * @param  list<array<string, mixed>>  $inventory
 * @return array{
 *   file_count: int,
 *   example_count: int,
 *   suite_counts: array<string, int>,
 *   area_counts: array<string, array{files: int, examples: int}>,
 *   signal_counts: array<string, int>
 * }
 */
function summarizeInventory(array $inventory): array
{
    $suiteCounts = [];
    $areaCounts = [];
    $signalCounts = [
        'files_with_mock_signals' => 0,
        'files_with_http_fake' => 0,
        'files_with_db_refresh' => 0,
        'files_with_filesystem_signals' => 0,
        'redirect_only_candidates' => 0,
        'smoke_or_markup_candidates' => 0,
        'mock_heavy_candidates' => 0,
        'happy_path_http_candidates' => 0,
    ];

    $exampleCount = 0;

    foreach ($inventory as $row) {
        $suiteCounts[$row['suite']] = ($suiteCounts[$row['suite']] ?? 0) + 1;

        if (! isset($areaCounts[$row['area']])) {
            $areaCounts[$row['area']] = ['files' => 0, 'examples' => 0];
        }

        $areaCounts[$row['area']]['files']++;
        $areaCounts[$row['area']]['examples'] += $row['examples'];
        $exampleCount += $row['examples'];

        incrementSignalCounts($signalCounts, $row);
    }

    arsort($suiteCounts);
    uasort($areaCounts, fn (array $left, array $right): int => $right['examples'] <=> $left['examples']);

    return [
        'file_count' => count($inventory),
        'example_count' => $exampleCount,
        'suite_counts' => $suiteCounts,
        'area_counts' => $areaCounts,
        'signal_counts' => $signalCounts,
    ];
}

/**
 * @param  array<string, int>  $signalCounts
 * @param  array<string, mixed>  $row
 */
function incrementSignalCounts(array &$signalCounts, array $row): void
{
    $signalCounts['files_with_mock_signals'] += ($row['mock_signals'] ?? 0) > 0 ? 1 : 0;
    $signalCounts['files_with_http_fake'] += ($row['http_fake'] ?? 0) > 0 ? 1 : 0;
    $signalCounts['files_with_db_refresh'] += ($row['refresh_db'] ?? 0) > 0 ? 1 : 0;
    $signalCounts['files_with_filesystem_signals'] += ($row['filesystem_signals'] ?? 0) > 0 ? 1 : 0;

    $reasons = is_array($row['reasons'] ?? null) ? $row['reasons'] : [];
    $signalCounts['redirect_only_candidates'] += in_array('redirect-only', $reasons, true) ? 1 : 0;
    $signalCounts['smoke_or_markup_candidates'] += in_array('smoke-or-markup', $reasons, true) ? 1 : 0;
    $signalCounts['mock_heavy_candidates'] += in_array('mock-heavy', $reasons, true) ? 1 : 0;
    $signalCounts['happy_path_http_candidates'] += in_array('happy-path-http', $reasons, true) ? 1 : 0;
}

/**
 * @param  array<string, mixed>  $summary
 * @param  list<array<string, mixed>>  $inventory
 */
function renderInventoryReport(array $summary, array $inventory, string $projectRoot): string
{
    usort($inventory, function (array $left, array $right): int {
        if ($left['score'] === $right['score']) {
            if ($left['examples'] === $right['examples']) {
                return strcmp($left['path'], $right['path']);
            }

            return $right['examples'] <=> $left['examples'];
        }

        return $right['score'] <=> $left['score'];
    });

    $topCandidates = array_slice(array_filter($inventory, fn (array $row): bool => $row['score'] > 0), 0, 20);
    $topAreas = array_slice($summary['area_counts'], 0, 12, true);

    $lines = [];
    $lines[] = '# Test Suite Audit Inventory';
    $lines[] = '';
    $lines[] = '**Agent:** Codex';
    $lines[] = '**Status:** Inventory and progress snapshot';
    $lines[] = '**Last Updated:** '.date('Y-m-d');
    $lines[] = '**Sources:** `scripts/test-suite-audit-inventory.php`, `tests/`, `docs/plans/test-suite-audit.md`, `docs/plans/ai-test-suite-audit.md`, attempted `php artisan test --profile` on 2026-04-21';
    $lines[] = '';
    $lines[] = '## Problem Essence';
    $lines[] = '';
    $lines[] = 'A useful audit needs a first-pass map of the suite: where the examples are concentrated, which files lean on mocks or isolated filesystem setup, and which files match common weak-test shapes such as redirect-only checks or smoke-style markup assertions.';
    $lines[] = '';
    $lines[] = '## Desired Outcome';
    $lines[] = '';
    $lines[] = 'This report gives BLB both a ranked starting point and a compact view of the remaining endgame. The signals below are heuristics, not verdicts; they identify likely audit candidates and concentration areas so human review can decide keep, tighten, merge, or delete.';
    $lines[] = '';
    $lines[] = '## Audit Progress Snapshot';
    $lines[] = '';
    $lines[] = '### Completed Or Mature Slices';
    $lines[] = '';

    foreach (completedOrMatureSlices() as $line) {
        $lines[] = '- '.$line;
    }

    $lines[] = '';
    $lines[] = '### Current Checkpoint';
    $lines[] = '';

    foreach (currentCheckpointLines() as $line) {
        $lines[] = '- '.$line;
    }

    $lines[] = '';
    $lines[] = '### Next Recommended Slices';
    $lines[] = '';

    foreach (nextRecommendedSlices() as $line) {
        $lines[] = '- '.$line;
    }

    $lines[] = '';
    $lines[] = '### Remaining Buckets After That';
    $lines[] = '';

    foreach (remainingBucketsAfterThat() as $line) {
        $lines[] = '- '.$line;
    }
    $lines[] = '';
    $lines[] = '## Summary';
    $lines[] = '';
    $lines[] = '- PHP files scanned: '.$summary['file_count'];
    $lines[] = '- `it()` / `test()` examples detected: '.$summary['example_count'];
    $lines[] = '- Files with Mockery signals: '.$summary['signal_counts']['files_with_mock_signals'];
    $lines[] = '- Files with `Http::fake()`: '.$summary['signal_counts']['files_with_http_fake'];
    $lines[] = '- Files with DB refresh traits: '.$summary['signal_counts']['files_with_db_refresh'];
    $lines[] = '- Files with filesystem-isolation signals: '.$summary['signal_counts']['files_with_filesystem_signals'];
    $lines[] = '- Redirect-only candidates: '.$summary['signal_counts']['redirect_only_candidates'];
    $lines[] = '- Smoke-or-markup candidates: '.$summary['signal_counts']['smoke_or_markup_candidates'];
    $lines[] = '- Mock-heavy candidates: '.$summary['signal_counts']['mock_heavy_candidates'];
    $lines[] = '- Happy-path HTTP candidates: '.$summary['signal_counts']['happy_path_http_candidates'];
    $lines[] = '';
    $lines[] = 'Runtime note: `php artisan test --profile` did not return a clean final profile in this environment, so runtime is not ranked per file here. Treat runtime as a scheduled or CI-backed follow-up signal, not a blocker for the first manual audit pass.';
    $lines[] = '';
    $lines[] = '## Suite Breakdown';
    $lines[] = '';
    $lines[] = '| Suite | Files |';
    $lines[] = '| --- | ---: |';

    foreach ($summary['suite_counts'] as $suite => $count) {
        $lines[] = '| '.$suite.' | '.$count.' |';
    }

    $lines[] = '';
    $lines[] = '## Top Areas By Example Count';
    $lines[] = '';
    $lines[] = '| Area | Files | Examples |';
    $lines[] = '| --- | ---: | ---: |';

    foreach ($topAreas as $area => $stats) {
        $lines[] = '| '.$area.' | '.$stats['files'].' | '.$stats['examples'].' |';
    }

    $lines[] = '';
    $lines[] = '## Ranked Audit Candidates';
    $lines[] = '';
    $lines[] = '| Score | File | Area | Examples | Signals |';
    $lines[] = '| ---: | --- | --- | ---: | --- |';

    foreach ($topCandidates as $row) {
        $lines[] = sprintf(
            '| %d | [%s](/%s) | %s | %d | %s |',
            $row['score'],
            basename($row['path']),
            ltrim($projectRoot.'/'.$row['path'], '/'),
            $row['area'],
            $row['examples'],
            implode(', ', $row['reasons']),
        );
    }

    $lines[] = '';
    $lines[] = '## Heuristic Notes';
    $lines[] = '';
    $lines[] = '- `redirect-only` flags files dominated by redirect assertions without broader behavior checks.';
    $lines[] = '- `smoke-or-markup` flags files with multiple `assertSee()` checks and no stronger interaction signals.';
    $lines[] = '- `mock-heavy` flags files with large Mockery / `shouldReceive()` volume; many will still be valid, but they deserve a behavior-vs-scaffolding review.';
    $lines[] = '- `happy-path-http` flags files that fake HTTP but show no obvious error-path assertions.';
    $lines[] = '- `filesystem-sensitive` marks tests that create and clean up temporary storage paths; these are not bad by themselves, but they are worth checking against the runtime-storage rule in `tests/AGENTS.md`.';
    $lines[] = '';
    $lines[] = '## Updated Read Of The Inventory';
    $lines[] = '';
    $lines[] = '- The original ranking did its job for prioritization, but it is no longer the full story because many of the top-ranked files are already audited.';
    $lines[] = '- Cheap-candidate signals should continue to guide ordering, not decisions.';
    $lines[] = '- Outside AI, the remaining high-confidence audit work is effectively exhausted; the endgame now points mostly back to Base/AI once that worktree is safe to touch.';

    return implode(PHP_EOL, $lines).PHP_EOL;
}

/**
 * @return list<string>
 */
function completedOrMatureSlices(): array
{
    return [
        '`Modules/Core/AI` companion audit: complete at this checkpoint; see [ai-test-suite-audit.md](/home/kiat/repo/laravel/blb/docs/plans/ai-test-suite-audit.md:1)',
        'Auth and Settings cheap-candidate slice: reviewed with real tightenings in password reset and password confirmation',
        'Authz and System cheap-candidate slice: `ImpersonationTest.php`, `RoleUiTest.php`, and `LocalizationUiTest.php` reviewed; `RoleUiTest.php` tightened',
        'Company feature slice: `CompanyUiTest.php`, `CompanyRelationshipTest.php`, and `ExternalAccessTest.php` tightened; `CompanyTest.php` and `CompanyTimezoneTest.php` kept',
        'Quality cheap-candidate slice: `QualityWorkflowUiTest.php` reviewed as `keep`',
        'User feature slice: `UserUiTest.php` and `PagePinningTest.php` reviewed; both kept with targeted tightenings',
        'Remaining auth and system feature slices: `RegistrationTest.php` tightened; `TransportTestUiTest.php` reviewed as `keep`',
        'Database feature slice: reviewed as `keep`',
        'Smaller leftover feature sweep: `AddressUiTest.php` tightened; audit, foundation, and workflow feature files reviewed as `keep`',
        'Small Base unit slice: authz middleware and locale bootstrap tightened; authz registry, actor, database settings, and database exception contracts reviewed as `keep`',
        'Remaining Base support/date-time/menu slice: `FileTest.php` tightened for storage isolation; date-time, menu, and support helper tests reviewed as `keep`',
        'Foundation unit slice: `ProviderRegistryTest.php` tightened; BLB exception contract test reviewed as `keep`',
        'Remaining user/timezone slice: `TimezoneCycleTest.php` tightened for employee-scope persistence; `PasswordUpdateTest.php` and `UserTest.php` reviewed as `keep`',
        'Final non-AI leftovers: `ExampleTest.php` deleted; `tests/Pest.php` cleaned of unused stock scaffolding while retaining the shared helpers in active use',
        'Phase 5 guardrails: changed-test linting, PR review prompt, and scheduled slow-test plus mutation-style reporting are in place',
    ];
}

/**
 * @return list<string>
 */
function currentCheckpointLines(): array
{
    return [
        'The audit is past the pilot stage and no longer blocked on process design',
        'Cheap-candidate heuristics have been useful for ranking, but have produced multiple false positives',
        'Outside AI, the remaining high-confidence audit work is effectively exhausted; most non-AI feature, Base/helper, and infrastructure slices are now reviewed',
    ];
}

/**
 * @return list<string>
 */
function nextRecommendedSlices(): array
{
    return [
        'Base/AI unit slice: `LlmClientToolCallingTest.php`, provider/model catalog tests, and related service files',
        'Remaining unit/service sweep outside AI: none obvious from the current inventory; revisit only if a fresh read surfaces a non-AI cluster that still looks materially under-audited',
    ];
}

/**
 * @return list<string>
 */
function remainingBucketsAfterThat(): array
{
    return [
        'Feature modules not yet audited in this program: none of the remaining feature-only buckets are still unreviewed; the endgame is now mostly unit/service slices',
        'Non-AI unit/service clusters that still look promising: none are obvious from the current inventory snapshot; the endgame now points mostly back to Base/AI once that worktree is safe to touch',
        'Revisit Phase 5 later to decide whether any scheduled guardrail is mature enough to become PR-blocking',
    ];
}
