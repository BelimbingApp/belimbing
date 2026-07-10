<?php

use App\Base\Foundation\Livewire\Concerns\FiltersByPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

function periodFilterHarness(): object
{
    return new class
    {
        use FiltersByPeriod {
            FiltersByPeriod::mountFiltersByPeriod as public;
            FiltersByPeriod::updatedPeriod as public;
            FiltersByPeriod::updatedFrom as public;
            FiltersByPeriod::updatedTo as public;
            FiltersByPeriod::periodRange as public;
        }

        public bool $pageReset = false;

        public function resetPage(): void
        {
            $this->pageReset = true;
        }
    };
}

it('applies the default period on mount', function (): void {
    Carbon::setTestNow('2026-07-10 10:00:00');

    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();

    expect($component->period)->toBe('last_30_days')
        ->and($component->from)->toBe('2026-06-11')
        ->and($component->to)->toBe('2026-07-10');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('switches to custom when date endpoints are edited', function (): void {
    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();

    $component->from = '2026-01-01';
    $component->updatedFrom();

    expect($component->period)->toBe('custom')
        ->and($component->pageReset)->toBeTrue();
});

it('reports inverted custom ranges', function (): void {
    $component = periodFilterHarness();
    $component->period = 'custom';
    $component->from = '2026-04-30';
    $component->to = '2026-04-01';

    [, , $error] = $component->periodRange();

    expect($error)->toBe('Start date must be on or before end date.');
});

it('opens custom ranges as drafts and restores the previous preset on cancel', function (): void {
    Carbon::setTestNow('2026-07-10 10:00:00');

    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();
    $component->period = 'custom';
    $component->updatedPeriod('custom');

    expect($component->periodRangeModalOpen)->toBeTrue()
        ->and($component->periodDraftFrom)->toBe('2026-06-11')
        ->and($component->periodDraftTo)->toBe('2026-07-10');

    $component->cancelPeriodRangeModal();

    expect($component->period)->toBe('last_30_days')
        ->and($component->periodRangeModalOpen)->toBeFalse();
});

it('applies a valid custom range from the modal', function (): void {
    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();
    $component->openPeriodRangeModal();
    $component->periodDraftFrom = '2026-07-01';
    $component->periodDraftTo = '2026-07-10';
    $component->applyPeriodRangeModal();

    expect($component->period)->toBe('custom')
        ->and($component->from)->toBe('2026-07-01')
        ->and($component->to)->toBe('2026-07-10')
        ->and($component->periodRangeModalOpen)->toBeFalse()
        ->and($component->pageReset)->toBeTrue();
});

it('treats backdrop and escape dismissal like cancel', function (): void {
    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();
    $component->period = 'custom';
    $component->updatedPeriod('custom');
    $component->periodRangeModalOpen = false;
    $component->updatedPeriodRangeModalOpen(false);

    expect($component->period)->toBe('last_30_days')
        ->and($component->periodRangeModalOpen)->toBeFalse();
});

it('keeps an inverted draft range open with a useful error', function (): void {
    $component = periodFilterHarness();
    $component->mountFiltersByPeriod();
    $component->openPeriodRangeModal();
    $component->periodDraftFrom = '2026-07-10';
    $component->periodDraftTo = '2026-07-01';
    $component->applyPeriodRangeModal();

    expect($component->periodRangeModalOpen)->toBeTrue()
        ->and($component->periodDraftError)->toBe('Start date must be on or before end date.');
});
