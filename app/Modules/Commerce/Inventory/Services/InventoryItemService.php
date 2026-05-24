<?php

namespace App\Modules\Commerce\Inventory\Services;

use App\Base\Foundation\ValueObjects\Money;
use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class InventoryItemService
{
    private const string PHOTO_DISK = 'local';

    public function __construct(private readonly MediaAssetStore $mediaAssets) {}

    /**
     * @param  array{sku: string, title: string, notes?: string|null, status: string, quantityOnHand?: int|string|null, storageLocation?: string|null, unitCostAmount?: string|null, targetPriceAmount?: string|null, currencyCode: string, categoryId?: int|null, productTemplateId?: int|null}  $data
     */
    public function create(int $companyId, array $data): Item
    {
        $currencyCode = strtoupper($data['currencyCode']);

        return Item::query()->create([
            'company_id' => $companyId,
            'category_id' => $data['categoryId'] ?? null,
            'product_template_id' => $data['productTemplateId'] ?? null,
            'sku' => strtoupper($data['sku']),
            'status' => $data['status'],
            'title' => $data['title'],
            'quantity_on_hand' => max(0, (int) ($data['quantityOnHand'] ?? 1)),
            'storage_location' => trim((string) ($data['storageLocation'] ?? '')) !== '' ? trim((string) $data['storageLocation']) : null,
            'notes' => $data['notes'] ?? null,
            'unit_cost_amount' => Money::fromDecimalString($data['unitCostAmount'] ?? null, $currencyCode)?->minorAmount,
            'target_price_amount' => Money::fromDecimalString($data['targetPriceAmount'] ?? null, $currencyCode)?->minorAmount,
            'currency_code' => $currencyCode,
        ]);
    }

    public function uploadPhoto(Item $item, UploadedFile $file, int $sortOrder): ItemPhoto
    {
        return DB::transaction(function () use ($item, $file, $sortOrder): ItemPhoto {
            $asset = $this->mediaAssets->putUploadedFile(
                self::PHOTO_DISK,
                'commerce/inventory/item-photos/'.$item->id,
                $file,
            );

            return ItemPhoto::query()->create([
                'item_id' => $item->id,
                'media_asset_id' => $asset->id,
                'sort_order' => $sortOrder,
            ]);
        });
    }

    public function deletePhoto(ItemPhoto $photo): void
    {
        DB::transaction(function () use ($photo): void {
            $asset = $photo->mediaAsset;
            $photo->delete();
            $this->mediaAssets->delete($asset);
        });
    }
}
