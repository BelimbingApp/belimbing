<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

const WLP_RUN_ID = 'run_wlp_pathological';

beforeEach(function (): void {
    config()->set('ai.wire_logging.enabled', true);

    $this->wireLogRoot = storage_path('framework/testing/wire-logger-cap-'.Str::random(16));
    File::ensureDirectoryExists($this->wireLogRoot);

    app()->instance(WireLogger::class, new class($this->wireLogRoot) extends WireLogger
    {
        public function __construct(private readonly string $root) {}

        public function path(string $runId): string
        {
            return $this->root.'/'.$runId.'.jsonl';
        }
    });
});

afterEach(function (): void {
    if (isset($this->wireLogRoot) && is_string($this->wireLogRoot)) {
        File::deleteDirectory($this->wireLogRoot);
    }
});

it('caps the preview entry count when a pathological stream block exceeds the extension ceiling', function (): void {
    $wireLogger = app(WireLogger::class);
    $path = $wireLogger->path(WLP_RUN_ID);

    // Pathological case: thousands of consecutive `llm.stream_line` entries.
    // The pre-cap implementation extended the page indefinitely to keep the
    // stream block intact; the cap collapses the trailing run into a single
    // placeholder with a bounded entry count.
    $totalStreamLines = 5_000;
    $lines = [];

    for ($i = 0; $i < $totalStreamLines; $i++) {
        $lines[] = json_encode([
            'at' => '2026-05-09T10:00:00+00:00',
            'type' => 'llm.stream_line',
            'raw_line' => 'data: chunk-'.$i,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    File::put($path, implode("\n", $lines)."\n");

    // Ask for limit=1 — the first stream entry fills the page, then the
    // extender takes over until the cap (200) is hit, then collapses.
    $preview = $wireLogger->preview(WLP_RUN_ID, offset: 0, limit: 1);

    // Hard ceiling enforced: 1 page entry + 200 extended + 1 placeholder = 202 max.
    expect(count($preview['entries']))->toBeLessThanOrEqual(202)
        ->and(count($preview['entries']))->toBeGreaterThan(1);

    $placeholder = end($preview['entries']);

    expect($placeholder['type'])->toBe('stream_lines_collapsed')
        ->and($placeholder['count'])->toBeGreaterThan(0)
        ->and($placeholder['from_entry_number'])->toBeInt()
        ->and($placeholder['to_entry_number'])->toBeInt()
        ->and($placeholder['from_entry_number'])->toBeLessThanOrEqual($placeholder['to_entry_number'])
        ->and($placeholder['count'])
        ->toBe($placeholder['to_entry_number'] - $placeholder['from_entry_number'] + 1);

    // Total entries reported on disk remains accurate.
    expect($preview['total_entries'])->toBe($totalStreamLines);
});
