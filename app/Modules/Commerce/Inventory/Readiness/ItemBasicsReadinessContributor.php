<?php

namespace App\Modules\Commerce\Inventory\Readiness;

use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Plugins\Contracts\CommerceReadinessContributor;

class ItemBasicsReadinessContributor implements CommerceReadinessContributor
{
    public function id(): string
    {
        return 'commerce.inventory.item-basics';
    }

    /**
     * @return list<array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string}>
     */
    public function readinessForItem(Item $item): array
    {
        $item->loadMissing('category', 'productTemplate', 'photos', 'descriptions');

        return [
            $this->catalogEntry($item),
            $this->priceEntry($item),
            $this->quantityEntry($item),
            $this->photoEntry($item),
            $this->descriptionEntry($item),
        ];
    }

    /** @return array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string} */
    private function catalogEntry(Item $item): array
    {
        if ($item->category_id !== null || $item->product_template_id !== null) {
            return [
                'code' => 'commerce.inventory.catalog.assigned',
                'severity' => 'success',
                'label' => 'Catalog fit is assigned',
                'description' => 'The item has a category or template to drive structured attributes.',
                'action' => 'catalog_fit',
            ];
        }

        return [
            'code' => 'commerce.inventory.catalog.missing',
            'severity' => 'warning',
            'label' => 'Choose a catalog fit',
            'description' => 'Assign a category or template so the item gets the right structured fields.',
            'action' => 'catalog_fit',
        ];
    }

    /** @return array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string} */
    private function priceEntry(Item $item): array
    {
        if ($item->target_price_amount !== null && $item->target_price_amount > 0) {
            return [
                'code' => 'commerce.inventory.price.present',
                'severity' => 'success',
                'label' => 'Target price is set',
                'description' => 'The item has a default selling price for marketplace draft work.',
                'action' => 'item_facts',
            ];
        }

        return [
            'code' => 'commerce.inventory.price.missing',
            'severity' => 'warning',
            'label' => 'Set a target price',
            'description' => 'Add the intended selling price before publishing or comparing listing performance.',
            'action' => 'item_facts',
        ];
    }

    /** @return array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string} */
    private function quantityEntry(Item $item): array
    {
        if ($item->quantity_on_hand > 0) {
            return [
                'code' => 'commerce.inventory.quantity.available',
                'severity' => 'success',
                'label' => 'Quantity is available',
                'description' => 'Stock on hand is greater than zero.',
                'action' => 'item_facts',
            ];
        }

        return [
            'code' => 'commerce.inventory.quantity.empty',
            'severity' => 'blocker',
            'label' => 'Quantity is zero',
            'description' => 'Increase quantity before listing an item that should be available for sale.',
            'action' => 'item_facts',
        ];
    }

    /** @return array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string} */
    private function photoEntry(Item $item): array
    {
        if ($item->photos->isNotEmpty()) {
            return [
                'code' => 'commerce.inventory.photos.present',
                'severity' => 'success',
                'label' => 'Photos are attached',
                'description' => 'At least one item photo is available.',
                'action' => 'photos',
            ];
        }

        return [
            'code' => 'commerce.inventory.photos.missing',
            'severity' => 'warning',
            'label' => 'Add item photos',
            'description' => 'Photos are expected before marketplace listing work.',
            'action' => 'photos',
        ];
    }

    /** @return array{code: string, severity: 'success'|'blocker'|'warning'|'suggestion', label: string, description?: string, action?: string} */
    private function descriptionEntry(Item $item): array
    {
        if ($item->descriptions->isNotEmpty()) {
            return [
                'code' => 'commerce.inventory.description.present',
                'severity' => 'success',
                'label' => 'Listing copy exists',
                'description' => 'At least one buyer-facing description draft is available.',
                'action' => 'descriptions',
            ];
        }

        return [
            'code' => 'commerce.inventory.description.missing',
            'severity' => 'suggestion',
            'label' => 'Add listing copy',
            'description' => 'Write buyer-facing description text before publishing.',
            'action' => 'descriptions',
        ];
    }
}
