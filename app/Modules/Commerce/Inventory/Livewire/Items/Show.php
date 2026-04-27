<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Show extends Component
{
    public Item $item;

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item;
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, string $currencyCode): string
    {
        if ($amount === null) {
            return '—';
        }

        return $currencyCode.' '.number_format($amount / 100, 2);
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.show');
    }
}
