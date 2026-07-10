<?php

namespace App\Base\Foundation\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Shared preset/custom date-range filter for report and history screens.
 *
 * Components may override {@see defaultPeriod()} or {@see periodOptions()}.
 * Call {@see periodRange()} from render/query code to get normalized day
 * boundaries plus a validation message when the custom range is inverted.
 */
trait FiltersByPeriod
{
    #[Url(as: 'period')]
    public string $period = 'last_30_days';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public bool $periodRangeModalOpen = false;

    public string $periodDraftFrom = '';

    public string $periodDraftTo = '';

    public ?string $periodDraftError = null;

    public string $periodFallback = 'last_30_days';

    public bool $periodRangeModalRevertsOnCancel = false;

    public function mountFiltersByPeriod(): void
    {
        $this->period = array_key_exists($this->period, $this->periodOptions())
            ? $this->period
            : $this->defaultPeriod();

        if ($this->period !== 'custom') {
            $this->periodFallback = $this->period;
            $this->applyPresetPeriodRange($this->period);

            return;
        }

        if ($this->from === '') {
            $this->from = Carbon::today()->subDays(29)->toDateString();
        }

        if ($this->to === '') {
            $this->to = Carbon::today()->toDateString();
        }
    }

    public function updatedPeriod(string $period): void
    {
        if (! array_key_exists($period, $this->periodOptions())) {
            $this->period = $this->defaultPeriod();
            $period = $this->period;
        }

        if ($period !== 'custom') {
            $this->periodFallback = $period;
            $this->periodRangeModalOpen = false;
            $this->applyPresetPeriodRange($period);

            $this->periodFilterUpdated();

            return;
        }

        $this->openPeriodRangeModal(revertOnCancel: true);
    }

    public function updatedFrom(): void
    {
        $this->markPeriodAsCustom();
    }

    public function updatedTo(): void
    {
        $this->markPeriodAsCustom();
    }

    private function markPeriodAsCustom(): void
    {
        $this->period = 'custom';
        $this->periodFilterUpdated();
    }

    public function openPeriodRangeModal(bool $revertOnCancel = false): void
    {
        $today = Carbon::today();

        $this->periodDraftFrom = $this->from !== ''
            ? $this->from
            : $today->copy()->subDays(29)->toDateString();
        $this->periodDraftTo = $this->to !== ''
            ? $this->to
            : $today->toDateString();
        $this->periodDraftError = null;
        $this->periodRangeModalRevertsOnCancel = $revertOnCancel;
        $this->periodRangeModalOpen = true;
    }

    public function applyPeriodRangeModal(): void
    {
        $from = $this->parsePeriodDate($this->periodDraftFrom);
        $to = $this->parsePeriodDate($this->periodDraftTo);

        if ($from === null || $to === null) {
            $this->periodDraftError = __('Choose a valid start and end date.');

            return;
        }

        if ($from->greaterThan($to)) {
            $this->periodDraftError = __('Start date must be on or before end date.');

            return;
        }

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
        $this->period = 'custom';
        $this->periodDraftError = null;
        $this->periodRangeModalRevertsOnCancel = false;
        $this->periodRangeModalOpen = false;

        $this->periodFilterUpdated();
    }

    public function cancelPeriodRangeModal(): void
    {
        if ($this->periodRangeModalRevertsOnCancel) {
            $fallback = array_key_exists($this->periodFallback, $this->periodOptions())
                && $this->periodFallback !== 'custom'
                    ? $this->periodFallback
                    : $this->defaultPeriod();

            $this->period = $fallback;
            $this->applyPresetPeriodRange($fallback);
            $this->periodFilterUpdated();
        }

        $this->periodDraftError = null;
        $this->periodRangeModalRevertsOnCancel = false;
        $this->periodRangeModalOpen = false;
    }

    public function updatedPeriodRangeModalOpen(bool $open): void
    {
        if ($open) {
            return;
        }

        if ($this->periodRangeModalRevertsOnCancel) {
            $this->cancelPeriodRangeModal();

            return;
        }

        $this->periodDraftError = null;
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            'this_month' => __('This month'),
            'last_7_days' => __('Last 7 days'),
            'last_30_days' => __('Last 30 days'),
            'last_90_days' => __('Last 90 days'),
            'custom' => __('Custom range'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: ?string}
     */
    protected function periodRange(): array
    {
        $today = Carbon::today();

        try {
            $from = Carbon::parse($this->from)->startOfDay();
        } catch (\Throwable) {
            $from = $today->copy()->subDays(29)->startOfDay();
        }

        try {
            $to = Carbon::parse($this->to)->endOfDay();
        } catch (\Throwable) {
            $to = $today->copy()->endOfDay();
        }

        if ($from->greaterThan($to)) {
            return [$from, $to, __('Start date must be on or before end date.')];
        }

        return [$from, $to, null];
    }

    protected function defaultPeriod(): string
    {
        return 'last_30_days';
    }

    protected function periodFilterUpdated(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    private function applyPresetPeriodRange(string $period): void
    {
        $today = Carbon::today();

        [$from, $to] = match ($period) {
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'last_7_days' => [$today->copy()->subDays(6), $today],
            'last_90_days' => [$today->copy()->subDays(89), $today],
            default => [$today->copy()->subDays(29), $today],
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    private function parsePeriodDate(string $value): ?Carbon
    {
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->toDateString() === $value ? $date : null;
    }
}
