<?php

use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;

it('stores an original asset with the canonical kind', function (): void {
    $store = app(MediaAssetStore::class);

    $asset = $store->storeOriginal('local', 'media/originals/abc.jpg', [
        'original_filename' => 'headlight.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 12_345,
    ]);

    expect($asset->isOriginal())->toBeTrue()
        ->and($asset->parent_id)->toBeNull()
        ->and($asset->kind)->toBe(MediaAsset::KIND_ORIGINAL)
        ->and($asset->disk)->toBe('local')
        ->and($asset->storage_key)->toBe('media/originals/abc.jpg')
        ->and($asset->original_filename)->toBe('headlight.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->file_size)->toBe(12_345);
});

it('stores a derivative linked to a parent original', function (): void {
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal('local', 'media/originals/abc.jpg');

    $cleaned = $store->storeDerivative(
        $original,
        'background_removed',
        'local',
        'media/derived/abc-cleaned.png',
        [
            'mime_type' => 'image/png',
            'metadata' => ['model' => 'rembg-v1', 'cost_micro_usd' => 200],
        ],
    );

    expect($cleaned->parent_id)->toBe($original->id)
        ->and($cleaned->kind)->toBe('background_removed')
        ->and($cleaned->isOriginal())->toBeFalse()
        ->and($cleaned->metadata)->toBe(['model' => 'rembg-v1', 'cost_micro_usd' => 200])
        ->and($original->fresh()->derivatives->pluck('id')->all())->toBe([$cleaned->id])
        ->and($cleaned->parent->is($original->fresh()))->toBeTrue();
});

it('rejects deriving with the canonical original kind', function (): void {
    $store = app(MediaAssetStore::class);
    $original = $store->storeOriginal('local', 'media/originals/abc.jpg');

    $store->storeDerivative($original, MediaAsset::KIND_ORIGINAL, 'local', 'media/wrong.png');
})->throws(InvalidArgumentException::class);

it('rejects empty disk or storage key', function (): void {
    $store = app(MediaAssetStore::class);

    expect(fn () => $store->storeOriginal('', 'media/x.jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->storeOriginal('local', '   '))->toThrow(InvalidArgumentException::class);
});

it('cascades derivative deletion when the parent is deleted', function (): void {
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal('local', 'media/originals/abc.jpg');
    $derivative = $store->storeDerivative($original, 'background_removed', 'local', 'media/derived/abc.png');

    $original->delete();

    expect(MediaAsset::query()->whereKey($derivative->id)->exists())->toBeFalse();
});
