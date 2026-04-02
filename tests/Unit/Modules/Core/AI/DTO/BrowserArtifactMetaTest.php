<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\BrowserArtifactMeta;
use App\Modules\Core\AI\Enums\BrowserArtifactType;

const BROWSER_ARTIFACT_URL = 'https://example.com';
const BROWSER_ARTIFACT_CREATED_AT = '2026-01-01T00:00:00+00:00';

describe('BrowserArtifactMeta', function () {
    it('constructs with all fields', function () {
        $meta = new BrowserArtifactMeta(
            artifactId: 'ba_test',
            sessionId: 'bs_test',
            type: BrowserArtifactType::Screenshot,
            storagePath: 'browser-artifacts/bs_test/ba_test.png',
            mimeType: 'image/png',
            sizeBytes: 1024,
            relatedUrl: BROWSER_ARTIFACT_URL,
            relatedTabId: 'tab1',
            createdAt: BROWSER_ARTIFACT_CREATED_AT,
        );

        expect($meta->artifactId)->toBe('ba_test')
            ->and($meta->sessionId)->toBe('bs_test')
            ->and($meta->type)->toBe(BrowserArtifactType::Screenshot)
            ->and($meta->mimeType)->toBe('image/png')
            ->and($meta->sizeBytes)->toBe(1024)
            ->and($meta->relatedUrl)->toBe(BROWSER_ARTIFACT_URL)
            ->and($meta->relatedTabId)->toBe('tab1');
    });

    it('creates from array', function () {
        $meta = BrowserArtifactMeta::fromArray([
            'artifact_id' => 'ba_from',
            'session_id' => 'bs_from',
            'type' => 'snapshot',
            'storage_path' => 'browser-artifacts/bs_from/ba_from.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 42,
            'related_url' => null,
            'related_tab_id' => null,
            'created_at' => BROWSER_ARTIFACT_CREATED_AT,
        ]);

        expect($meta->artifactId)->toBe('ba_from')
            ->and($meta->type)->toBe(BrowserArtifactType::Snapshot)
            ->and($meta->sizeBytes)->toBe(42);
    });

    it('converts to array', function () {
        $meta = new BrowserArtifactMeta(
            artifactId: 'ba_arr',
            sessionId: 'bs_arr',
            type: BrowserArtifactType::Pdf,
            storagePath: 'browser-artifacts/bs_arr/ba_arr.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 2048,
            relatedUrl: 'https://example.com/doc',
            relatedTabId: null,
            createdAt: BROWSER_ARTIFACT_CREATED_AT,
        );

        $array = $meta->toArray();

        expect($array['artifact_id'])->toBe('ba_arr')
            ->and($array['type'])->toBe('pdf')
            ->and($array['mime_type'])->toBe('application/pdf')
            ->and($array['size_bytes'])->toBe(2048);
    });

    it('round-trips through fromArray and toArray', function () {
        $original = [
            'artifact_id' => 'ba_rt',
            'session_id' => 'bs_rt',
            'type' => 'evaluate_result',
            'storage_path' => 'browser-artifacts/bs_rt/ba_rt.json',
            'mime_type' => 'application/json',
            'size_bytes' => 100,
            'related_url' => BROWSER_ARTIFACT_URL,
            'related_tab_id' => 'tab1',
            'created_at' => BROWSER_ARTIFACT_CREATED_AT,
        ];

        $meta = BrowserArtifactMeta::fromArray($original);

        expect($meta->toArray())->toBe($original);
    });
});
