<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Tool Catalog — searchable overview of all registered Agent tools.

namespace App\Modules\Core\AI\Livewire\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Modules\Core\AI\Services\ToolReadinessService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Catalog extends Component
{
    public string $search = '';

    public string $categoryFilter = '';

    /** @var string Column to sort by: 'name' | 'category' | 'risk' */
    public string $sortBy = 'name';

    /** @var string Sort direction: 'asc' | 'desc' */
    public string $sortDir = 'asc';

    public function sortOn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function render(): View
    {
        $readinessService = app(ToolReadinessService::class);
        $snapshots = $readinessService->allSnapshots();

        // Apply search filter
        if ($this->search !== '') {
            $search = mb_strtolower($this->search);
            $snapshots = array_filter($snapshots, function ($snap) use ($search) {
                $meta = $snap['metadata'];

                return str_contains(mb_strtolower($meta->displayName), $search)
                    || str_contains(mb_strtolower($meta->summary), $search)
                    || str_contains(mb_strtolower($meta->name), $search)
                    || str_contains(mb_strtolower($meta->category->label()), $search);
            });
        }

        // Apply category filter
        if ($this->categoryFilter !== '') {
            $snapshots = array_filter($snapshots, function ($snap) {
                return $snap['metadata']->category->value === $this->categoryFilter;
            });
        }

        // Sort
        $dir = $this->sortDir === 'asc' ? 1 : -1;
        uasort($snapshots, function ($a, $b) use ($dir) {
            $result = match ($this->sortBy) {
                'category' => $a['metadata']->category->sortOrder() <=> $b['metadata']->category->sortOrder()
                    ?: strcmp($a['metadata']->displayName, $b['metadata']->displayName),
                'risk' => $a['metadata']->riskClass->sortOrder() <=> $b['metadata']->riskClass->sortOrder()
                    ?: strcmp($a['metadata']->displayName, $b['metadata']->displayName),
                default => strcmp($a['metadata']->displayName, $b['metadata']->displayName),
            };

            return $result * $dir;
        });

        // Build category dropdown sorted by display order
        $categories = [];
        $sortedCases = ToolCategory::cases();
        usort($sortedCases, fn ($a, $b) => $a->sortOrder() <=> $b->sortOrder());
        foreach ($sortedCases as $cat) {
            $categories[$cat->value] = $cat->label();
        }

        return view('livewire.admin.ai.tools.catalog', [
            'snapshots' => $snapshots,
            'categories' => $categories,
        ]);
    }
}
