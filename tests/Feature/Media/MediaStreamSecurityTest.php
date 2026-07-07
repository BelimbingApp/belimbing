<?php

use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Http\Controllers\MediaAssetController;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderBag;

const MEDIA_STREAM_SECURITY_DISK = 'media';

function mediaStreamSecurityHeadersFor(string $key, string $mime, string $filename): HeaderBag
{
    Storage::disk(MEDIA_STREAM_SECURITY_DISK)->put($key, 'bytes');

    $asset = MediaAsset::query()->create([
        'disk' => MEDIA_STREAM_SECURITY_DISK,
        'storage_key' => $key,
        'kind' => MediaAsset::KIND_ORIGINAL,
        'original_filename' => $filename,
        'mime_type' => $mime,
    ]);

    return (new MediaAssetController)->stream($asset)->headers;
}

function expectMediaStreamHeaders(HeaderBag $headers, string $contentType, string $disposition): void
{
    expect($headers->get('Content-Type'))->toContain($contentType)
        ->and($headers->get('Content-Disposition'))->toContain($disposition)
        ->and($headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($headers->get('Content-Security-Policy'))->toContain('sandbox');
}

beforeEach(fn () => Storage::fake(MEDIA_STREAM_SECURITY_DISK));

it('serves a safe raster image inline', function (): void {
    $headers = mediaStreamSecurityHeadersFor('items/pic.png', 'image/png', 'pic.png');

    expectMediaStreamHeaders($headers, 'image/png', 'inline');
});

it('forces an SVG to download as an opaque octet-stream', function (): void {
    $headers = mediaStreamSecurityHeadersFor('items/x.svg', 'image/svg+xml', 'x.svg');

    expectMediaStreamHeaders($headers, 'application/octet-stream', 'attachment');
});

it('forces an unknown/HTML type to download', function (): void {
    $headers = mediaStreamSecurityHeadersFor('docs/page.html', 'text/html', 'page.html');

    expectMediaStreamHeaders($headers, 'application/octet-stream', 'attachment');
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

    $asset = app(MediaAssetStore::class)->putUploadedFile(MEDIA_STREAM_SECURITY_DISK, 'uploads', $png);

    expect($asset->exists)->toBeTrue()
        ->and($asset->mime_type)->toContain('image/');
});
