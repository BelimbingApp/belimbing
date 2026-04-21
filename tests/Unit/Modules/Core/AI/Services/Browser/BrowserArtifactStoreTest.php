<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserArtifactType;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionRepository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\CreatesLaraFixtures;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, CreatesLaraFixtures::class);

beforeEach(function () {
    $this->artifactDir = 'framework/testing/browser-artifacts-'.Str::random(16);
    config()->set('ai.tools.browser.artifact_dir', $this->artifactDir);

    $this->repository = new BrowserSessionRepository;
    $this->store = new BrowserArtifactStore;

    // Create a persistent session to own artifacts.
    $fixture = $this->createLaraFixture();
    $this->session = $this->repository->create($fixture['employee']->id, $fixture['company']->id, true, 300);
    $this->repository->markReady($this->session);
});

afterEach(function () {
    // Clean up written files.
    $this->store->deleteForSession($this->session->id);
    File::deleteDirectory(storage_path('app/'.$this->artifactDir));
});

describe('store', function () {
    it('stores a snapshot artifact on disk and database', function () {
        $meta = $this->store->store(
            sessionId: $this->session->id,
            type: BrowserArtifactType::Snapshot,
            content: '<h1>Hello World</h1>',
            relatedUrl: 'https://example.com',
        );

        expect($meta->artifactId)->toStartWith('ba_')
            ->and($meta->sessionId)->toBe($this->session->id)
            ->and($meta->type)->toBe(BrowserArtifactType::Snapshot)
            ->and($meta->storagePath)->toStartWith($this->artifactDir.'/'.$this->session->id.'/')
            ->and($meta->mimeType)->toBe('text/plain')
            ->and($meta->sizeBytes)->toBe(20)
            ->and($meta->relatedUrl)->toBe('https://example.com');
    });

    it('stores a screenshot artifact', function () {
        $meta = $this->store->store(
            sessionId: $this->session->id,
            type: BrowserArtifactType::Screenshot,
            content: 'fake-png-data',
        );

        expect($meta->type)->toBe(BrowserArtifactType::Screenshot)
            ->and($meta->mimeType)->toBe('image/png')
            ->and(str_ends_with($meta->storagePath, '.png'))->toBeTrue();
    });

    it('stores a PDF artifact', function () {
        $meta = $this->store->store(
            sessionId: $this->session->id,
            type: BrowserArtifactType::Pdf,
            content: 'fake-pdf-data',
        );

        expect($meta->type)->toBe(BrowserArtifactType::Pdf)
            ->and($meta->mimeType)->toBe('application/pdf')
            ->and(str_ends_with($meta->storagePath, '.pdf'))->toBeTrue();
    });

    it('stores an evaluate result artifact', function () {
        $meta = $this->store->store(
            sessionId: $this->session->id,
            type: BrowserArtifactType::EvaluateResult,
            content: '{"title":"Example"}',
        );

        expect($meta->type)->toBe(BrowserArtifactType::EvaluateResult)
            ->and($meta->mimeType)->toBe('application/json')
            ->and(str_ends_with($meta->storagePath, '.json'))->toBeTrue();
    });
});

describe('find', function () {
    it('returns null for non-existent artifact', function () {
        expect($this->store->find('ba_nonexistent'))->toBeNull();
    });

    it('finds stored artifact by ID', function () {
        $stored = $this->store->store(
            $this->session->id, BrowserArtifactType::Snapshot, 'test content',
        );

        $found = $this->store->find($stored->artifactId);

        expect($found)->not()->toBeNull()
            ->and($found->artifactId)->toBe($stored->artifactId);
    });
});

describe('listForSession', function () {
    it('returns empty array for session with no artifacts', function () {
        expect($this->store->listForSession($this->session->id))->toBe([]);
    });

    it('lists all artifacts for a session', function () {
        $this->store->store($this->session->id, BrowserArtifactType::Snapshot, 'content1');
        $this->store->store($this->session->id, BrowserArtifactType::Screenshot, 'content2');

        $list = $this->store->listForSession($this->session->id);

        expect($list)->toHaveCount(2)
            ->and($list[0]->type)->toBe(BrowserArtifactType::Snapshot)
            ->and($list[1]->type)->toBe(BrowserArtifactType::Screenshot);
    });

    it('does not return artifacts from other sessions', function () {
        $this->store->store($this->session->id, BrowserArtifactType::Snapshot, 'mine');

        $fixture2 = $this->createLaraFixture();
        $other = $this->repository->create($fixture2['employee']->id, $fixture2['company']->id, true, 300);
        $this->store->store($other->id, BrowserArtifactType::Snapshot, 'not mine');

        expect($this->store->listForSession($this->session->id))->toHaveCount(1);

        // Clean up other session artifacts.
        $this->store->deleteForSession($other->id);
    });
});

describe('readContent', function () {
    it('returns null for non-existent artifact', function () {
        expect($this->store->readContent('ba_nonexistent'))->toBeNull();
    });

    it('reads stored content from disk', function () {
        $stored = $this->store->store(
            $this->session->id, BrowserArtifactType::Snapshot, 'Hello BLB',
        );

        expect($this->store->readContent($stored->artifactId))->toBe('Hello BLB');
    });
});

describe('deleteForSession', function () {
    it('returns 0 for session with no artifacts', function () {
        expect($this->store->deleteForSession($this->session->id))->toBe(0);
    });

    it('deletes artifacts from disk and database', function () {
        $a1 = $this->store->store($this->session->id, BrowserArtifactType::Snapshot, 'c1');
        $a2 = $this->store->store($this->session->id, BrowserArtifactType::Screenshot, 'c2');

        $deleted = $this->store->deleteForSession($this->session->id);

        expect($deleted)->toBe(2)
            ->and($this->store->find($a1->artifactId))->toBeNull()
            ->and($this->store->find($a2->artifactId))->toBeNull()
            ->and($this->store->readContent($a1->artifactId))->toBeNull()
            ->and($this->store->readContent($a2->artifactId))->toBeNull();
    });
});
