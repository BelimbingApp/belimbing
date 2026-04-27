<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Services;

use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Inventory\Exceptions\InventoryStorageException;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventoryItemService
{
    private const string PHOTO_DISK = 'local';

    /**
     * @param  array{title: string, description?: string|null, status: string, unitCostAmount?: string|null, targetPriceAmount?: string|null, currencyCode: string}  $data
     */
    public function create(int $companyId, array $data): Item
    {
        $currencyCode = strtoupper($data['currencyCode']);

        return Item::query()->create([
            'company_id' => $companyId,
            'sku' => $this->generateSku(),
            'status' => $data['status'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'unit_cost_amount' => Money::fromDecimalString($data['unitCostAmount'] ?? null, $currencyCode)?->minorAmount,
            'target_price_amount' => Money::fromDecimalString($data['targetPriceAmount'] ?? null, $currencyCode)?->minorAmount,
            'currency_code' => $currencyCode,
        ]);
    }

    public function uploadPhoto(Item $item, UploadedFile $file, int $sortOrder): ItemPhoto
    {
        $filename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        $storageKey = $file->store(
            'commerce/inventory/item-photos/'.$item->id,
            ['disk' => self::PHOTO_DISK],
        );

        if ($storageKey === false) {
            throw InventoryStorageException::photoStoreFailed();
        }

        return ItemPhoto::query()->create([
            'item_id' => $item->id,
            'filename' => $filename,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'sort_order' => $sortOrder,
        ]);
    }

    public function deletePhoto(ItemPhoto $photo): void
    {
        DB::transaction(function () use ($photo): void {
            $storageKey = $photo->storage_key;
            $photo->delete();
            Storage::disk(self::PHOTO_DISK)->delete($storageKey);
        });
    }

    private function generateSku(): string
    {
        do {
            $sku = 'ITEM-'.Str::upper(Str::random(8));
        } while (Item::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
