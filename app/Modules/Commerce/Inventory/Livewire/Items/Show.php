<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load('photos');
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

                if ($field === 'description' && trim((string) $validatedValue) === '') {
                    $model->description = null;
                }
            },
        );

        $this->item->refresh();
    }

    public function uploadPhotos(): void
    {
        $this->authorizeUpdate();

        $this->validate([
            'photoFiles' => ['required', 'array', 'min:1', 'max:12'],
            'photoFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        DB::transaction(function (): void {
            $maxSort = (int) $this->item->photos()->max('sort_order');
            $sort = $maxSort;

            foreach ($this->photoFiles as $file) {
                if (! $file) {
                    continue;
                }

                $sort++;

                // Capture metadata before store(): store() moves the file off Livewire's temp disk,
                // and getSize()/getMimeType() may otherwise stat the removed livewire-tmp path.
                $filename = $file->getClientOriginalName();
                $mimeType = $file->getMimeType();
                $fileSize = $file->getSize();

                $storageKey = $file->store(
                    'commerce/inventory/item-photos/'.$this->item->id,
                    ['disk' => 'local'],
                );

                ItemPhoto::query()->create([
                    'item_id' => $this->item->id,
                    'filename' => $filename,
                    'storage_key' => $storageKey,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'sort_order' => $sort,
                ]);
            }
        });

        $this->photoFiles = [];
        $this->item->load('photos');
    }

    public function deletePhoto(int $photoId): void
    {
        $this->authorizeUpdate();

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        DB::transaction(function () use ($photo): void {
            $storageKey = $photo->storage_key;
            $photo->delete();
            Storage::disk('local')->delete($storageKey);
        });

        $this->item->load('photos');
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

        $this->item->update([$field => $this->parseMoneyAmount($validated[$field] ?? null)]);
        $this->item->refresh();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
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
        if ($amount === null) {
            return '—';
        }

        return $currencyCode.' '.number_format($amount / 100, 2);
    }

    public function canEdit(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'inventory.item.update')
            ->allowed;
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
        ]);
    }

    public function formatMoneyInput(?int $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return number_format($amount / 100, 2, '.', '');
    }

    private function authorizeUpdate(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'inventory.item.update',
        );
    }

    private function parseMoneyAmount(mixed $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }
}
