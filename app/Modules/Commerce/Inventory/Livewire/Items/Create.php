<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Services\DefaultCurrencyResolver;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $title = '';

    public ?string $description = null;

    public string $status = Item::STATUS_DRAFT;

    public ?string $unitCostAmount = null;

    public ?string $targetPriceAmount = null;

    public string $currencyCode = 'MYR';

    public function mount(DefaultCurrencyResolver $currencyResolver): void
    {
        $this->currencyCode = $currencyResolver->forCompany(Auth::user()?->company_id);
    }

    public function store(InventoryItemService $items): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'unitCostAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'targetPriceAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'currencyCode' => ['required', 'string', 'size:3'],
        ]);

        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            Session::flash('error', __('Your account must belong to a company before you can create inventory items.'));

            return;
        }

        $item = $items->create($companyId, $validated);

        Session::flash('success', __('Item created successfully.'));

        $this->redirect(route('commerce.inventory.items.show', $item), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.create', [
            'statuses' => Item::statuses(),
        ]);
    }
}
