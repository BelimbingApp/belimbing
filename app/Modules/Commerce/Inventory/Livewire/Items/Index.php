<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Foundation\Livewire\SearchablePaginatedList;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

class Index extends SearchablePaginatedList
{
    protected const string VIEW_NAME = 'livewire.commerce.inventory.items.index';

    protected const string VIEW_DATA_KEY = 'items';

    protected const string SORT_COLUMN = 'created_at';

    public string $search = '';

    protected function sortableColumns(): array
    {
        return [
            'sku' => 'commerce_inventory_items.sku',
            'title' => 'commerce_inventory_items.title',
            'status' => 'commerce_inventory_items.status',
            'quantity_on_hand' => 'commerce_inventory_items.quantity_on_hand',
            'fitments_count' => 'fitments_count',
            'storage_location' => 'commerce_inventory_items.storage_location',
            'unit_cost_amount' => 'commerce_inventory_items.unit_cost_amount',
            'target_price_amount' => 'commerce_inventory_items.target_price_amount',
            'created_at' => 'commerce_inventory_items.created_at',
        ];
    }

    protected function defaultSortDirections(): array
    {
        return [
            'sku' => 'asc',
            'title' => 'asc',
            'status' => 'asc',
            'quantity_on_hand' => 'desc',
            'fitments_count' => 'desc',
            'storage_location' => 'asc',
            'unit_cost_amount' => 'asc',
            'target_price_amount' => 'asc',
            'created_at' => 'desc',
        ];
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
        return Money::format($amount, $currencyCode);
    }

    protected function query(): EloquentBuilder|QueryBuilder
    {
        $companyId = Auth::user()?->company_id;

        return Item::query()
            ->with('fitments')
            ->withCount('fitments')
            ->when($companyId !== null, fn (EloquentBuilder $query) => $query->where('company_id', $companyId))
            ->when($companyId === null, fn (EloquentBuilder $query) => $query->whereRaw('1 = 0'));
    }

    protected function applySearch(EloquentBuilder|QueryBuilder $query, string $search): void
    {
        $query->where(function (EloquentBuilder $builder) use ($search): void {
            $builder->where('sku', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('notes', 'like', '%'.$search.'%')
                ->orWhere('status', 'like', '%'.$search.'%')
                ->orWhere('storage_location', 'like', '%'.$search.'%');
        });
    }
}
