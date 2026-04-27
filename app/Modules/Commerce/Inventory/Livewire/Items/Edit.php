<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Edit extends Component
{
    public Item $item;

    public string $title = '';

    public ?string $description = null;

    public string $status = Item::STATUS_DRAFT;

    public ?string $unitCostAmount = null;

    public ?string $targetPriceAmount = null;

    public string $currencyCode = 'MYR';

    public function mount(Item $item): void
    {
        $this->authorizeCompanyItem($item);

        $this->item = $item;
        $this->title = $item->title;
        $this->description = $item->description;
        $this->status = $item->status;
        $this->unitCostAmount = $this->formatMoneyInput($item->unit_cost_amount);
        $this->targetPriceAmount = $this->formatMoneyInput($item->target_price_amount);
        $this->currencyCode = $item->currency_code;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'unitCostAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'targetPriceAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'currencyCode' => ['required', 'string', 'size:3'],
        ]);

        $this->item->update([
            'status' => $validated['status'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'unit_cost_amount' => $this->parseMoneyAmount($validated['unitCostAmount'] ?? null),
            'target_price_amount' => $this->parseMoneyAmount($validated['targetPriceAmount'] ?? null),
            'currency_code' => strtoupper($validated['currencyCode']),
        ]);

        Session::flash('success', __('Item updated successfully.'));

        $this->redirect(route('commerce.inventory.items.show', $this->item), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.edit', [
            'statuses' => Item::statuses(),
        ]);
    }

    private function authorizeCompanyItem(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }
    }

    private function formatMoneyInput(?int $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return number_format($amount / 100, 2, '.', '');
    }

    private function parseMoneyAmount(?string $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }
}
