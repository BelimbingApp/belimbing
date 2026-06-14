<?php

use App\Base\Media\Models\MediaAsset;

test('displayUrl returns the public url for an external link asset', function (): void {
    $asset = new MediaAsset([
        'disk' => MediaAsset::DISK_EXTERNAL,
        'metadata' => ['public_url' => 'https://i.ebayimg.com/abc.jpg'],
    ]);

    expect($asset->displayUrl())->toBe('https://i.ebayimg.com/abc.jpg');
});

test('displayUrl returns a signed stream url for a stored-file asset', function (): void {
    $asset = new MediaAsset(['disk' => 'local', 'storage_key' => 'items/x.jpg']);
    $asset->id = 4242;

    $url = $asset->displayUrl();

    expect($url)->toContain('signature=')
        ->and($url)->not->toContain('i.ebayimg.com');
});

test('displayUrl fails fast when an external asset has no safe http(s) url', function (): void {
    $asset = new MediaAsset([
        'disk' => MediaAsset::DISK_EXTERNAL,
        'metadata' => ['public_url' => 'file:///etc/passwd'],
    ]);

    $asset->displayUrl();
})->throws(RuntimeException::class);
