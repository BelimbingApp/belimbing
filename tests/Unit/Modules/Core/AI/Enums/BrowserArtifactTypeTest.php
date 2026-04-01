<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserArtifactType;
use Tests\TestCase;

uses(TestCase::class);

describe('BrowserArtifactType', function () {
    it('has the expected cases', function () {
        expect(BrowserArtifactType::cases())->toHaveCount(4);
    });

    describe('label', function () {
        it('returns translated labels for all cases', function () {
            foreach (BrowserArtifactType::cases() as $case) {
                expect($case->label())->toBeString()->not()->toBeEmpty();
            }
        });
    });

    describe('mimeType', function () {
        it('returns correct MIME types', function () {
            expect(BrowserArtifactType::Snapshot->mimeType())->toBe('text/plain')
                ->and(BrowserArtifactType::Screenshot->mimeType())->toBe('image/png')
                ->and(BrowserArtifactType::Pdf->mimeType())->toBe('application/pdf')
                ->and(BrowserArtifactType::EvaluateResult->mimeType())->toBe('application/json');
        });
    });

    it('has string backing values', function () {
        expect(BrowserArtifactType::Snapshot->value)->toBe('snapshot')
            ->and(BrowserArtifactType::Screenshot->value)->toBe('screenshot')
            ->and(BrowserArtifactType::Pdf->value)->toBe('pdf')
            ->and(BrowserArtifactType::EvaluateResult->value)->toBe('evaluate_result');
    });
});
