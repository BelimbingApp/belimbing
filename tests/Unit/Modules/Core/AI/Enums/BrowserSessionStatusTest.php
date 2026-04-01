<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use Tests\TestCase;

uses(TestCase::class);

describe('BrowserSessionStatus', function () {
    it('has the expected cases', function () {
        expect(BrowserSessionStatus::cases())->toHaveCount(6);
    });

    describe('isTerminal', function () {
        it('marks Expired, Failed, Closed as terminal', function () {
            expect(BrowserSessionStatus::Expired->isTerminal())->toBeTrue()
                ->and(BrowserSessionStatus::Failed->isTerminal())->toBeTrue()
                ->and(BrowserSessionStatus::Closed->isTerminal())->toBeTrue();
        });

        it('marks Opening, Ready, Busy as non-terminal', function () {
            expect(BrowserSessionStatus::Opening->isTerminal())->toBeFalse()
                ->and(BrowserSessionStatus::Ready->isTerminal())->toBeFalse()
                ->and(BrowserSessionStatus::Busy->isTerminal())->toBeFalse();
        });
    });

    describe('isActionable', function () {
        it('only Ready is actionable', function () {
            expect(BrowserSessionStatus::Ready->isActionable())->toBeTrue();

            foreach (BrowserSessionStatus::cases() as $case) {
                if ($case !== BrowserSessionStatus::Ready) {
                    expect($case->isActionable())->toBeFalse();
                }
            }
        });
    });

    describe('label', function () {
        it('returns translated labels for all cases', function () {
            foreach (BrowserSessionStatus::cases() as $case) {
                expect($case->label())->toBeString()->not()->toBeEmpty();
            }
        });
    });

    describe('color', function () {
        it('returns color strings for all cases', function () {
            $expected = [
                'Opening' => 'yellow',
                'Ready' => 'green',
                'Busy' => 'blue',
                'Expired' => 'gray',
                'Failed' => 'red',
                'Closed' => 'gray',
            ];

            foreach (BrowserSessionStatus::cases() as $case) {
                expect($case->color())->toBe($expected[$case->name]);
            }
        });
    });
});
