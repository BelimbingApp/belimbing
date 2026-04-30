<?php

use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

it('puts an uploaded file to disk and registers the original', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');

    $asset = $store->putUploadedFile('local', 'media/originals', $file);

    expect($asset->isOriginal())->toBeTrue()
        ->and($asset->disk)->toBe('local')
        ->and($asset->original_filename)->toBe('headlight.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->file_size)->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($asset->storage_key);
});

it('puts derivative bytes alongside an original', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $original = $store->storeOriginal('local', 'media/originals/abc.jpg');

    $cleaned = $store->putDerivativeBytes(
        $original,
        'background_removed',
        'local',
        'media/derived/abc.png',
        'PNG-BYTES',
        ['mime_type' => 'image/png'],
    );

    expect($cleaned->parent_id)->toBe($original->id)
        ->and($cleaned->mime_type)->toBe('image/png')
        ->and($cleaned->file_size)->toBe(strlen('PNG-BYTES'));

    Storage::disk('local')->assertExists($cleaned->storage_key);
    expect(Storage::disk('local')->get($cleaned->storage_key))->toBe('PNG-BYTES');
});

it('deletes both the row and the file', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');
    $asset = $store->putUploadedFile('local', 'media/originals', $file);

    $store->delete($asset);

    expect(MediaAsset::query()->whereKey($asset->id)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($asset->storage_key);
});

it('wraps a failed upload in MediaStorageException', function (): void {
    $store = app(MediaAssetStore::class);

    $broken = new class('headlight.jpg', 'image/jpeg', null, null, true) extends UploadedFile
    {
        public function __construct(string $name, string $mime, ?int $size, ?int $error, bool $test)
        {
            parent::__construct(__FILE__, $name, $mime, $error, $test);
        }

        public function store($path = '', $options = []): false
        {
            return false;
        }
    };

    $store->putUploadedFile('local', 'media/originals', $broken);
})->throws(MediaStorageException::class);
