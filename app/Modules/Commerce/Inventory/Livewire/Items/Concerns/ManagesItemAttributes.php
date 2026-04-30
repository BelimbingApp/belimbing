<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items\Concerns;

use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

trait ManagesItemAttributes
{
    public ?int $selectedAttributeId = null;

    public string $attributeValue = '';

    public function updatedSelectedAttributeId(mixed $value): void
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            $this->selectedAttributeId = null;
            $this->attributeValue = '';
            $this->resetValidation(['selectedAttributeId', 'attributeValue']);
        }
    }

    public function saveAttributeValue(): void
    {
        $this->authorizeUpdate();

        $companyId = Auth::user()?->company_id;

        $validated = $this->validate([
            'selectedAttributeId' => [
                'required',
                'integer',
                Rule::in($this->applicableAttributeQuery($companyId)->pluck('id')->all()),
            ],
            'attributeValue' => ['required', 'string', 'max:1000'],
        ]);

        $attribute = $this->applicableAttributeQuery($companyId)
            ->findOrFail($validated['selectedAttributeId']);

        AttributeValue::query()->updateOrCreate(
            [
                'item_id' => $this->item->id,
                'attribute_id' => $attribute->id,
            ],
            [
                'value' => ['text' => $validated['attributeValue']],
                'display_value' => $validated['attributeValue'],
            ],
        );

        $this->reset('selectedAttributeId', 'attributeValue');
        $this->item->load('catalogAttributeValues.attribute');
    }

    public function removeAttributeValue(int $attributeValueId): void
    {
        $this->authorizeUpdate();

        $value = $this->item->catalogAttributeValues->firstWhere('id', $attributeValueId);

        if (! $value instanceof AttributeValue) {
            return;
        }

        $value->delete();
        $this->item->load('catalogAttributeValues.attribute');
    }

    /**
     * @return Builder<CatalogAttribute>
     */
    private function applicableAttributeQuery(?int $companyId): Builder
    {
        return $this->constrainApplicableAttributeQuery(
            CatalogAttribute::query()->where('company_id', $companyId),
        )
            ->with(['category', 'productTemplate'])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @param  Builder<CatalogAttribute>  $query
     * @return Builder<CatalogAttribute>
     */
    private function constrainApplicableAttributeQuery(Builder $query): Builder
    {
        $categoryId = $this->item->category_id;
        $templateId = $this->item->product_template_id;

        return $query->where(function (Builder $query) use ($categoryId, $templateId): void {
            $query->where(function (Builder $query): void {
                $query->whereNull('category_id')
                    ->whereNull('product_template_id');
            });

            if ($categoryId !== null) {
                $query->orWhere(function (Builder $query) use ($categoryId): void {
                    $query->where('category_id', $categoryId)
                        ->whereNull('product_template_id');
                });
            }

            if ($templateId !== null) {
                $query->orWhere('product_template_id', $templateId);
            }
        });
    }
}
