<?php

use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('streams the asset bytes when the URL is signed and current', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->createWithContent('headlight.jpg', 'JPEG-BYTES');
    $asset = $store->putUploadedFile('local', 'media/originals', $file);

    $url = $store->temporaryStreamUrl($asset);

    $response = $this->get($url);

    $response->assertOk()
        ->assertHeader('Content-Disposition');
    expect($response->streamedContent())->toBe('JPEG-BYTES');
});

it('rejects an unsigned request to the stream route', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');
    $asset = $store->putUploadedFile('local', 'media/originals', $file);

    $this->get(route('media.assets.stream', ['asset' => $asset->id]))
        ->assertForbidden();
});

it('rejects a signed URL after it expires', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $file = UploadedFile::fake()->create('headlight.jpg', 64, 'image/jpeg');
    $asset = $store->putUploadedFile('local', 'media/originals', $file);

    $url = URL::temporarySignedRoute('media.assets.stream', now()->subMinute(), ['asset' => $asset->id]);

    $this->get($url)->assertForbidden();
});

it('returns 404 when the asset row exists but the file is missing', function (): void {
    Storage::fake('local');
    $store = app(MediaAssetStore::class);

    $asset = $store->storeOriginal('local', 'media/originals/missing.jpg');

    $this->get($store->temporaryStreamUrl($asset))
        ->assertNotFound();
});
