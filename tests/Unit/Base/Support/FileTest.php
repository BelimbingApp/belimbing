<?php

use App\Base\Support\File;
use Tests\TestCase;

uses(TestCase::class);

function supportFileTestRoot(): string
{
    return storage_path('framework/testing/support-file');
}

afterEach(function (): void {
    $directory = supportFileTestRoot();

    if (is_dir($directory)) {
        deleteDirectoryRecursive($directory);
    }
});

it('creates missing parent directories when writing a file', function (): void {
    $path = supportFileTestRoot().'/nested/config.json';

    $bytes = File::put($path, '{"ok":true}');

    expect($bytes)->toBe(11)
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toBe('{"ok":true}');
});

it('appends file content when append flags are provided', function (): void {
    $path = supportFileTestRoot().'/session/transcript.jsonl';

    File::put($path, "first\n");
    File::put($path, "second\n", FILE_APPEND | LOCK_EX);

    expect(file_get_contents($path))->toBe("first\nsecond\n");
});

function deleteDirectoryRecursive(string $directory): void
{
    $entries = scandir($directory);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_dir($path)) {
            deleteDirectoryRecursive($path);

            continue;
        }

        unlink($path);
    }

    rmdir($directory);
}
