<?php

use App\Base\Media\Models\MediaAsset;
use App\Base\Media\PhotoCleanup\PhotoCleanupException;
use App\Base\Media\PhotoCleanup\PhotoCleanupService;
use App\Base\Media\PhotoCleanup\PhotoRoomConfiguration;
use App\Base\Media\Services\MediaAssetStore;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const PHOTO_CLEANUP_DISK = 'local';
const PHOTO_CLEANUP_ORIGINAL_KEY = 'commerce/inventory/item-photos/1/original.jpg';
const PHOTO_CLEANUP_ENDPOINT = 'https://sdk.photoroom.com/*';

function createOriginalPhotoAsset(string $bytes = 'ORIGINAL-JPEG-BYTES'): MediaAsset
{
    Storage::disk(PHOTO_CLEANUP_DISK)->put(PHOTO_CLEANUP_ORIGINAL_KEY, $bytes);

    return app(MediaAssetStore::class)->storeOriginal(PHOTO_CLEANUP_DISK, PHOTO_CLEANUP_ORIGINAL_KEY, [
        'original_filename' => 'part.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($bytes),
    ]);
}

beforeEach(function (): void {
    Storage::fake(PHOTO_CLEANUP_DISK);
});

it('returns no api key when photoroom is not configured for the company', function (): void {
    $companyId = configurePhotoRoom();
    AiProvider::query()->where('company_id', $companyId)->delete();

    $config = app(PhotoRoomConfiguration::class)->resolve($companyId);

    expect($config['api_key'])->toBeNull()
        ->and($config['api_base_url'])->toBe(PhotoRoomConfiguration::API_BASE_URL);
});

it('resolves the photoroom api key once configured', function (): void {
    $companyId = configurePhotoRoom('sandbox-key-123');

    $config = app(PhotoRoomConfiguration::class)->resolve($companyId);

    expect($config['api_key'])->toBe('sandbox-key-123');
});

it('throws when no api key is configured for the company', function (): void {
    $companyId = Company::factory()->create()->id;

    app(PhotoRoomConfiguration::class)->requireConfigured($companyId);
})->throws(PhotoCleanupException::class);

it('creates a background_removed derivative without modifying the original', function (): void {
    $companyId = configurePhotoRoom();

    Http::fake([
        PHOTO_CLEANUP_ENDPOINT => Http::response('CLEANED-PNG-BYTES', 200),
    ]);

    $original = createOriginalPhotoAsset();

    $derivative = app(PhotoCleanupService::class)->clean($original, $companyId);

    expect($derivative->parent_id)->toBe($original->id)
        ->and($derivative->kind)->toBe(MediaAsset::KIND_BACKGROUND_REMOVED)
        ->and($derivative->mime_type)->toBe('image/png')
        ->and($derivative->original_filename)->toBe('part.background_removed.png')
        ->and($derivative->metadata['provider'])->toBe(PhotoRoomConfiguration::PROVIDER)
        ->and($derivative->metadata['provider_label'])->toBe(PhotoRoomConfiguration::PROVIDER_LABEL)
        ->and($derivative->metadata['source_asset_id'])->toBe($original->id)
        ->and($derivative->metadata['status'])->toBe('ready')
        ->and($derivative->metadata['cleaned_at'])->toBeString();

    Storage::disk(PHOTO_CLEANUP_DISK)->assertExists($derivative->storage_key);
    expect(Storage::disk(PHOTO_CLEANUP_DISK)->get($derivative->storage_key))->toBe('CLEANED-PNG-BYTES');

    expect(Storage::disk(PHOTO_CLEANUP_DISK)->get($original->storage_key))->toBe('ORIGINAL-JPEG-BYTES')
        ->and($original->fresh()->kind)->toBe(MediaAsset::KIND_ORIGINAL)
        ->and($original->fresh()->metadata)->toBeNull();
});

it('replaces an existing derivative on retry, removing the old file', function (): void {
    $companyId = configurePhotoRoom();

    Http::fake([
        PHOTO_CLEANUP_ENDPOINT => Http::sequence()
            ->push('FIRST-PNG-BYTES', 200)
            ->push('SECOND-PNG-BYTES', 200),
    ]);

    $original = createOriginalPhotoAsset();
    $service = app(PhotoCleanupService::class);

    $first = $service->clean($original, $companyId);
    $second = $service->clean($original, $companyId);

    expect($second->id)->not->toBe($first->id)
        ->and($second->storage_key)->toBe($first->storage_key)
        ->and(MediaAsset::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(
            MediaAsset::query()
                ->where('parent_id', $original->id)
                ->where('kind', MediaAsset::KIND_BACKGROUND_REMOVED)
                ->count()
        )->toBe(1);

    expect(Storage::disk(PHOTO_CLEANUP_DISK)->get($second->storage_key))->toBe('SECOND-PNG-BYTES');
});

it('throws when PhotoRoom is not configured', function (): void {
    $companyId = Company::factory()->create()->id;
    $original = createOriginalPhotoAsset();

    app(PhotoCleanupService::class)->clean($original, $companyId);
})->throws(PhotoCleanupException::class);

it('throws when the source asset has no stored file', function (): void {
    $original = MediaAsset::query()->create([
        'parent_id' => null,
        'disk' => MediaAsset::DISK_EXTERNAL,
        'storage_key' => 'external/listing-photo',
        'kind' => MediaAsset::KIND_ORIGINAL,
        'original_filename' => 'listing-photo.jpg',
        'mime_type' => 'image/jpeg',
        'metadata' => ['public_url' => 'https://example.test/listing-photo.jpg'],
    ]);

    app(PhotoCleanupService::class)->clean($original, configurePhotoRoom());
})->throws(PhotoCleanupException::class);

it('throws when PhotoRoom rejects the request', function (): void {
    $companyId = configurePhotoRoom();

    Http::fake([
        PHOTO_CLEANUP_ENDPOINT => Http::response('Invalid image', 400),
    ]);

    $original = createOriginalPhotoAsset();

    app(PhotoCleanupService::class)->clean($original, $companyId);
})->throws(PhotoCleanupException::class);
