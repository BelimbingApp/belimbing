<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Services\ControlPlane\WireLogger;

trait ManagesWireLogWindow
{
    public int $wireLogOffset = 0;

    public int $wireLogLimit = 100;

    public string $wireLogStartEntry = '1';

    public function updatedWireLogLimit(): void
    {
        $this->wireLogLimit = $this->normalizeWireLogLimit($this->wireLogLimit);
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    public function firstWireLogEntries(): void
    {
        $this->wireLogOffset = 0;
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    public function previousWireLogEntries(): void
    {
        $this->wireLogOffset = max(0, $this->wireLogOffset - $this->normalizeWireLogLimit($this->wireLogLimit));
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    public function nextWireLogEntries(): void
    {
        $this->wireLogOffset += $this->normalizeWireLogLimit($this->wireLogLimit);
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    public function lastWireLogEntries(int $lastOffset): void
    {
        $this->wireLogOffset = max(0, $lastOffset);
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    public function jumpToWireLogEntry(int $totalEntries = 0): void
    {
        $startEntry = max(1, (int) $this->wireLogStartEntry);

        if ($totalEntries > 0) {
            $startEntry = min($startEntry, $totalEntries);
        }

        $this->wireLogOffset = $startEntry - 1;
        $this->syncWireLogStartEntry();
        $this->notifyWireLogWindowChanged();
    }

    protected function resetWireLogWindow(): void
    {
        $this->wireLogOffset = 0;
        $this->wireLogLimit = $this->normalizeWireLogLimit($this->wireLogLimit);
        $this->syncWireLogStartEntry();
    }

    private function syncWireLogStartEntry(): void
    {
        $this->wireLogStartEntry = (string) ($this->wireLogOffset + 1);
    }

    /**
     * Allowed page sizes for the wire-log inspector (must stay in sync with the Blade select and {@see WireLogger::preview} limit clamp).
     *
     * @return int One of 100, 250, 500, or 1000.
     */
    private function normalizeWireLogLimit(int $limit): int
    {
        $sizes = [100, 250, 500, 1000];

        foreach ($sizes as $size) {
            if ($limit <= $size) {
                return $size;
            }
        }

        return $sizes[array_key_last($sizes)];
    }

    private function notifyWireLogWindowChanged(): void
    {
        $this->dispatch('wire-log-window-changed');
    }
}
