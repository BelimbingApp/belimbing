<?php

use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const MEDIA_ASSET_STORE_DISK = 'local';
const MEDIA_ASSET_STORE_ORIGINAL_KEY = 'media/originals/abc.jpg';

it('stores an original asset with the canonical kind', function (): void {
    $store = app(MediaAssetStore::class);

    $asset = $store->storeOriginal(MEDIA_ASSET_STORE_DISK, MEDIA_ASSET_STORE_ORIGINAL_KEY, [
        'original_filename' => 'headlight.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 12_345,
    ]);

    expect($asset->isOriginal())->toBeTrue()
        ->and($asset->parent_id)->toBeNull()
        ->and($asset->kind)->toBe(MediaAsset::KIND_ORIGINAL)
        ->and($asset->disk)->toBe(MEDIA_ASSET_STORE_DISK)
        ->and($asset->storage_key)->toBe(MEDIA_ASSET_STORE_ORIGINAL_KEY)
        ->and($asset->original_filename)->toBe('headlight.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->file_size)->toBe(12_345);
});

it('stores a derivative linked to a parent original', function (): void {
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal(MEDIA_ASSET_STORE_DISK, MEDIA_ASSET_STORE_ORIGINAL_KEY);

    $cleaned = $store->storeDerivative(
        $original,
        'background_removed',
        MEDIA_ASSET_STORE_DISK,
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
    $original = $store->storeOriginal(MEDIA_ASSET_STORE_DISK, MEDIA_ASSET_STORE_ORIGINAL_KEY);

    $store->storeDerivative($original, MediaAsset::KIND_ORIGINAL, MEDIA_ASSET_STORE_DISK, 'media/wrong.png');
})->throws(InvalidArgumentException::class);

it('rejects empty disk or storage key', function (): void {
    $store = app(MediaAssetStore::class);

    expect(fn () => $store->storeOriginal('', 'media/x.jpg'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $store->storeOriginal(MEDIA_ASSET_STORE_DISK, '   '))->toThrow(InvalidArgumentException::class);
});

it('cascades derivative deletion when the parent is deleted', function (): void {
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal(MEDIA_ASSET_STORE_DISK, MEDIA_ASSET_STORE_ORIGINAL_KEY);
    $derivative = $store->storeDerivative($original, 'background_removed', MEDIA_ASSET_STORE_DISK, 'media/derived/abc.png');

    $original->delete();

    expect(MediaAsset::query()->whereKey($derivative->id)->exists())->toBeFalse();
});

it('puts an uploaded file to disk and registers the original', function (): void {
    Storage::fake(MEDIA_ASSET_STORE_DISK);
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');

    $asset = $store->putUploadedFile(MEDIA_ASSET_STORE_DISK, 'media/originals', $file);

    expect($asset->isOriginal())->toBeTrue()
        ->and($asset->disk)->toBe(MEDIA_ASSET_STORE_DISK)
        ->and($asset->original_filename)->toBe('headlight.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->file_size)->toBeGreaterThan(0);

    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertExists($asset->storage_key);
});

it('puts derivative bytes alongside an original', function (): void {
    Storage::fake(MEDIA_ASSET_STORE_DISK);
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal(MEDIA_ASSET_STORE_DISK, MEDIA_ASSET_STORE_ORIGINAL_KEY);

    $cleaned = $store->putDerivativeBytes(
        $original,
        'background_removed',
        MEDIA_ASSET_STORE_DISK,
        'media/derived/abc.png',
        'PNG-BYTES',
        ['mime_type' => 'image/png'],
    );

    expect($cleaned->parent_id)->toBe($original->id)
        ->and($cleaned->mime_type)->toBe('image/png')
        ->and($cleaned->file_size)->toBe(strlen('PNG-BYTES'));

    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertExists($cleaned->storage_key);
    expect(Storage::disk(MEDIA_ASSET_STORE_DISK)->get($cleaned->storage_key))->toBe('PNG-BYTES');
});

it('deletes both the row and the file', function (): void {
    Storage::fake(MEDIA_ASSET_STORE_DISK);
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');
    $asset = $store->putUploadedFile(MEDIA_ASSET_STORE_DISK, 'media/originals', $file);

    $store->delete($asset);

    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertMissing($asset->storage_key);
});

it('deletes derivative files when the parent is deleted', function (): void {
    Storage::fake(MEDIA_ASSET_STORE_DISK);
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->createWithContent('headlight.jpg', 'JPEG-BYTES');
    $original = $store->putUploadedFile(MEDIA_ASSET_STORE_DISK, 'media/originals', $file);

    $cleaned = $store->putDerivativeBytes(
        $original,
        'background_removed',
        MEDIA_ASSET_STORE_DISK,
        'media/derived/abc.png',
        'PNG-BYTES',
    );

    $grandchild = $store->putDerivativeBytes(
        $cleaned,
        'thumbnail',
        MEDIA_ASSET_STORE_DISK,
        'media/derived/abc-thumb.png',
        'THUMB-BYTES',
    );

    $store->delete($original);

    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertMissing($original->storage_key);
    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertMissing($cleaned->storage_key);
    Storage::disk(MEDIA_ASSET_STORE_DISK)->assertMissing($grandchild->storage_key);
    expect(MediaAsset::query()->whereKey($cleaned->id)->exists())->toBeFalse()
        ->and(MediaAsset::query()->whereKey($grandchild->id)->exists())->toBeFalse();
});

it('wraps a failed upload in MediaStorageException', function (): void {
    $store = app(MediaAssetStore::class);

    $broken = new class('headlight.jpg', 'image/jpeg', null, true) extends UploadedFile
    {
        public function __construct(string $name, string $mime, ?int $error, bool $test)
        {
            parent::__construct(__FILE__, $name, $mime, $error, $test);
        }

        public function store($path = '', $options = []): false
        {
            return false;
        }
    };

    $store->putUploadedFile(MEDIA_ASSET_STORE_DISK, 'media/originals', $broken);
})->throws(MediaStorageException::class);
