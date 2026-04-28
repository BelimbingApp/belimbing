<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Catalog\Models\Attribute as CatalogAttribute;
use App\Modules\Commerce\Catalog\Models\AttributeValue;
use App\Modules\Commerce\Catalog\Models\Description as CatalogDescription;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Show extends Component
{
    use SavesValidatedFields;
    use WithFileUploads;

    public Item $item;

    /**
     * @var array<int, mixed>
     */
    public array $photoFiles = [];

    public ?int $selectedAttributeId = null;

    public string $attributeValue = '';

    public string $descriptionTitle = '';

    public string $descriptionBody = '';

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load('photos', 'catalogAttributeValues.attribute', 'descriptions.createdByUser');
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        $this->saveValidatedField(
            $this->item,
            $field,
            $value,
            $this->fieldRules(),
            function ($model, string $field, mixed $validatedValue): void {
                if ($field === 'currency_code') {
                    $model->currency_code = strtoupper($validatedValue);
                }

                if ($field === 'notes' && trim((string) $validatedValue) === '') {
                    $model->notes = null;
                }
            },
        );

        $this->item->refresh();
    }

    public function uploadPhotos(InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->validate([
            'photoFiles' => ['required', 'array', 'min:1', 'max:12'],
            'photoFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        DB::transaction(function () use ($items): void {
            $maxSort = (int) $this->item->photos()->max('sort_order');
            $sort = $maxSort;

            foreach ($this->photoFiles as $file) {
                if (! $file) {
                    continue;
                }

                $sort++;
                $items->uploadPhoto($this->item, $file, $sort);
            }
        });

        $this->photoFiles = [];
        $this->item->load('photos');
    }

    public function deletePhoto(int $photoId, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $items->deletePhoto($photo);

        $this->item->load('photos');
    }

    public function saveAttributeValue(): void
    {
        $this->authorizeUpdate();

        $companyId = Auth::user()?->company_id;

        $validated = $this->validate([
            'selectedAttributeId' => [
                'required',
                'integer',
                Rule::exists(CatalogAttribute::class, 'id')->where('company_id', $companyId),
            ],
            'attributeValue' => ['required', 'string', 'max:1000'],
        ]);

        $attribute = CatalogAttribute::query()
            ->where('company_id', $companyId)
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

    public function addDescription(): void
    {
        $this->authorizeUpdate();

        $validated = $this->validate([
            'descriptionTitle' => ['required', 'string', 'max:255'],
            'descriptionBody' => ['required', 'string', 'max:10000'],
        ]);

        $nextVersion = ((int) $this->item->descriptions()->max('version')) + 1;

        CatalogDescription::query()->create([
            'item_id' => $this->item->id,
            'created_by_user_id' => Auth::id(),
            'version' => $nextVersion,
            'title' => $validated['descriptionTitle'],
            'body' => $validated['descriptionBody'],
            'source' => CatalogDescription::SOURCE_MANUAL,
        ]);

        $this->reset('descriptionTitle', 'descriptionBody');
        $this->item->load('descriptions.createdByUser');
    }

    public function acceptDescription(int $descriptionId): void
    {
        $this->authorizeUpdate();

        $description = $this->item->descriptions->firstWhere('id', $descriptionId);

        if (! $description instanceof CatalogDescription) {
            return;
        }

        DB::transaction(function () use ($description): void {
            $this->item->descriptions()->update(['is_accepted' => false]);
            $description->update(['is_accepted' => true]);
        });

        $this->item->load('descriptions.createdByUser');
    }

    public function saveMoneyField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if (! in_array($field, ['unit_cost_amount', 'target_price_amount'], true)) {
            return;
        }

        $validated = validator(
            [$field => $value],
            [$field => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']],
        )->validate();

        $this->item->update([
            $field => Money::fromDecimalString($validated[$field] ?? null, $this->item->currency_code)?->minorAmount,
        ]);
        $this->item->refresh();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'currency_code' => ['required', 'string', 'size:3'],
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

    public function canEdit(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'commerce.inventory_item.update')
            ->allowed;
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
            'availableAttributes' => CatalogAttribute::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with(['category', 'productTemplate'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function formatMoneyInput(?int $amount): ?string
    {
        return Money::formatInput($amount);
    }

    private function authorizeUpdate(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.inventory_item.update',
        );
    }
}
