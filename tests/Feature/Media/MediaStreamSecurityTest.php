<?php

use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Http\Controllers\MediaAssetController;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderBag;

function streamAsset(string $key, string $mime, string $filename): HeaderBag
{
    Storage::disk('media')->put($key, 'bytes');

    $asset = MediaAsset::query()->create([
        'disk' => 'media',
        'storage_key' => $key,
        'kind' => MediaAsset::KIND_ORIGINAL,
        'original_filename' => $filename,
        'mime_type' => $mime,
    ]);

    return (new MediaAssetController)->stream($asset)->headers;
}

beforeEach(fn () => Storage::fake('media'));

it('serves a safe raster image inline', function (): void {
    $headers = streamAsset('items/pic.png', 'image/png', 'pic.png');

    expect($headers->get('Content-Type'))->toContain('image/png')
        ->and($headers->get('Content-Disposition'))->toContain('inline')
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('forces an SVG to download as an opaque octet-stream', function (): void {
    $headers = streamAsset('items/x.svg', 'image/svg+xml', 'x.svg');

    expect($headers->get('Content-Type'))->toContain('application/octet-stream')
        ->and($headers->get('Content-Disposition'))->toContain('attachment')
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('forces an unknown/HTML type to download', function (): void {
    $headers = streamAsset('docs/page.html', 'text/html', 'page.html');

    expect($headers->get('Content-Type'))->toContain('application/octet-stream')
        ->and($headers->get('Content-Disposition'))->toContain('attachment');
});

it('refuses to store an SVG upload', function (): void {
    $svg = UploadedFile::fake()->createWithContent(
        'evil.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    expect(fn () => app(MediaAssetStore::class)->putUploadedFile('media', 'uploads', $svg))
        ->toThrow(MediaStorageException::class);
});

it('still stores a normal image upload', function (): void {
    // A real 1x1 PNG so getMimeType() reports image/png without needing GD.
    $pngBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    );
    $png = UploadedFile::fake()->createWithContent('photo.png', $pngBytes);

    $asset = app(MediaAssetStore::class)->putUploadedFile('media', 'uploads', $png);

    expect($asset->exists)->toBeTrue()
        ->and($asset->mime_type)->toContain('image/');
});
