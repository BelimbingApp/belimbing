<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items\Concerns;

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

trait ManagesItemCatalogFit
{
    public ?int $catalogCategoryId = null;

    public ?int $catalogProductTemplateId = null;

    public function updatedCatalogCategoryId(mixed $value): void
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            $this->catalogCategoryId = null;
        }

        $template = $this->selectedProductTemplate();

        if ($template instanceof ProductTemplate
            && $template->category_id !== null
            && $this->catalogCategoryId !== null
            && $template->category_id !== $this->catalogCategoryId) {
            $this->catalogProductTemplateId = null;
        }
    }

    public function updatedCatalogProductTemplateId(mixed $value): void
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            $this->catalogProductTemplateId = null;

            return;
        }

        $template = $this->selectedProductTemplate();

        if ($template instanceof ProductTemplate && $template->category_id !== null) {
            $this->catalogCategoryId = $template->category_id;
        }
    }

    public function saveCatalogAssignment(): void
    {
        $this->authorizeUpdate();

        $companyId = Auth::user()?->company_id;

        $validated = $this->validate([
            'catalogCategoryId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'catalogProductTemplateId' => ['nullable', 'integer', Rule::exists(ProductTemplate::class, 'id')->where('company_id', $companyId)],
        ]);

        $categoryId = $validated['catalogCategoryId'] ?? null;
        $templateId = $validated['catalogProductTemplateId'] ?? null;
        $template = null;

        if ($templateId !== null) {
            $template = ProductTemplate::query()
                ->where('company_id', $companyId)
                ->findOrFail($templateId);

            if ($template->category_id !== null && $categoryId !== null && $template->category_id !== $categoryId) {
                $this->addError('catalogProductTemplateId', __('The selected template belongs to a different category.'));

                return;
            }

            $categoryId ??= $template->category_id;
        }

        $this->item->update([
            'category_id' => $categoryId,
            'product_template_id' => $template?->id,
        ]);

        $this->item->load('category', 'productTemplate');
        $this->catalogCategoryId = $this->item->category_id;
        $this->catalogProductTemplateId = $this->item->product_template_id;
        $this->selectedAttributeId = null;
        $this->attributeValue = '';

        session()->flash('success', __('Catalog fit updated.'));
    }

    private function selectedProductTemplate(): ?ProductTemplate
    {
        if ($this->catalogProductTemplateId === null) {
            return null;
        }

        return ProductTemplate::query()
            ->where('company_id', Auth::user()?->company_id)
            ->find($this->catalogProductTemplateId);
    }
}
