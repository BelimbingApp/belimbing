<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemAttributes;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemCatalogFit;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemDescriptions;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemFitment;
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
    use ManagesItemAttributes;
    use ManagesItemCatalogFit;
    use ManagesItemDescriptions;
    use SavesValidatedFields;
    use WithFileUploads;

    public Item $item;

    /**
     * @var array<int, mixed>
     */
    public array $photoFiles = [];

    public bool $fitmentUniversal = false;

    public string $fitmentYear = '';

    public string $fitmentMake = '';

    public string $fitmentModel = '';

    public string $fitmentTrim = '';

    public string $fitmentEngine = '';

    public string $fitmentNotes = '';

    public string $fitmentBulk = '';

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load('category', 'productTemplate', 'photos', 'fitments', 'catalogAttributeValues.attribute', 'descriptions.createdByUser');
        $this->catalogCategoryId = $this->item->category_id;
        $this->catalogProductTemplateId = $this->item->product_template_id;
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if ($field === 'sku') {
            $value = strtoupper(trim((string) $value));
        }

        if ($field === 'storage_location' && trim((string) $value) === '') {
            $value = null;
        }

        $this->saveValidatedField(
            $this->item,
            $field,
            $value,
            $this->fieldRules(),
            function ($model, string $field, mixed $validatedValue): void {
                if ($field === 'currency_code') {
                    $model->currency_code = strtoupper($validatedValue);
                }

                if ($field === 'sku') {
                    $model->sku = strtoupper($validatedValue);
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

    public function addFitment(): void
    {
        $this->authorizeUpdate();

        $validated = validator([
            'fitmentUniversal' => $this->fitmentUniversal,
            'fitmentYear' => $this->fitmentYear,
            'fitmentMake' => $this->fitmentMake,
            'fitmentModel' => $this->fitmentModel,
            'fitmentTrim' => $this->fitmentTrim,
            'fitmentEngine' => $this->fitmentEngine,
            'fitmentNotes' => $this->fitmentNotes,
        ], [
            'fitmentUniversal' => ['boolean'],
            'fitmentYear' => ['nullable', 'string', 'max:40'],
            'fitmentMake' => ['nullable', 'string', 'max:80'],
            'fitmentModel' => ['nullable', 'string', 'max:120'],
            'fitmentTrim' => ['nullable', 'string', 'max:160'],
            'fitmentEngine' => ['nullable', 'string', 'max:160'],
            'fitmentNotes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        if (! $validated['fitmentUniversal'] && $this->fitmentProperties($validated) === []) {
            $this->addError('fitmentYear', __('Enter at least one compatibility value, or mark this item as universal fit.'));

            return;
        }

        $this->createFitment($validated);
        $this->resetFitmentForm();
        $this->item->load('fitments');
    }

    public function importFitments(): void
    {
        $this->authorizeUpdate();

        $validated = validator([
            'fitmentBulk' => $this->fitmentBulk,
        ], [
            'fitmentBulk' => ['required', 'string', 'max:10000'],
        ])->validate();

        $created = 0;
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $validated['fitmentBulk'])) ?: [] as $line) {
            $parts = array_map('trim', str_getcsv($line));
            if (array_filter($parts) === []) {
                continue;
            }

            $this->createFitment([
                'fitmentUniversal' => false,
                'fitmentYear' => $parts[0] ?? '',
                'fitmentMake' => $parts[1] ?? '',
                'fitmentModel' => $parts[2] ?? '',
                'fitmentTrim' => $parts[3] ?? '',
                'fitmentEngine' => $parts[4] ?? '',
                'fitmentNotes' => $parts[5] ?? '',
            ]);
            $created++;
        }

        $this->fitmentBulk = '';
        $this->item->load('fitments');
        session()->flash('success', trans_choice('Imported :count fitment entry.|Imported :count fitment entries.', $created, ['count' => $created]));
    }

    public function deleteFitment(int $fitmentId): void
    {
        $this->authorizeUpdate();

        ItemFitment::query()
            ->where('id', $fitmentId)
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->delete();

        $this->item->load('fitments');
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
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Item::class, 'sku')
                    ->where('company_id', $this->item->company_id)
                    ->ignore($this->item),
            ],
            'title' => ['required', 'string', 'max:255'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:999999'],
            'storage_location' => ['nullable', 'string', 'max:255'],
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
            ->can(Actor::forUser(Auth::user()), 'commerce.inventory.item.update')
            ->allowed;
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
            'availableAttributes' => $this->applicableAttributeQuery(Auth::user()?->company_id)->get(),
            'categories' => Category::query()
                ->where('company_id', Auth::user()?->company_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productTemplates' => ProductTemplate::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with('category')
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
            'commerce.inventory.item.update',
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createFitment(array $values): void
    {
        $universal = (bool) ($values['fitmentUniversal'] ?? false);

        ItemFitment::query()->create([
            'company_id' => $this->item->company_id,
            'item_id' => $this->item->id,
            'is_universal' => $universal,
            'compatibility_properties' => $universal ? null : $this->fitmentProperties($values),
            'display_year' => $universal ? null : $this->nullableFitmentValue($values['fitmentYear'] ?? null),
            'display_make' => $universal ? null : $this->nullableFitmentValue($values['fitmentMake'] ?? null),
            'display_model' => $universal ? null : $this->nullableFitmentValue($values['fitmentModel'] ?? null),
            'display_trim' => $universal ? null : $this->nullableFitmentValue($values['fitmentTrim'] ?? null),
            'display_engine' => $universal ? null : $this->nullableFitmentValue($values['fitmentEngine'] ?? null),
            'source' => ItemFitment::SOURCE_OPERATOR,
            'confidence' => ItemFitment::CONFIDENCE_SELLER_CONFIRMED,
            'notes' => $this->nullableFitmentValue($values['fitmentNotes'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function fitmentProperties(array $values): array
    {
        return collect([
            'Year' => $values['fitmentYear'] ?? null,
            'Make' => $values['fitmentMake'] ?? null,
            'Model' => $values['fitmentModel'] ?? null,
            'Trim' => $values['fitmentTrim'] ?? null,
            'Engine' => $values['fitmentEngine'] ?? null,
        ])
            ->map(fn (mixed $value): ?string => $this->nullableFitmentValue($value))
            ->filter()
            ->all();
    }

    private function nullableFitmentValue(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function resetFitmentForm(): void
    {
        $this->fitmentUniversal = false;
        $this->fitmentYear = '';
        $this->fitmentMake = '';
        $this->fitmentModel = '';
        $this->fitmentTrim = '';
        $this->fitmentEngine = '';
        $this->fitmentNotes = '';
    }
}
