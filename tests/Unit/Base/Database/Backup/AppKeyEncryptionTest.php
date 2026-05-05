<?php

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Encryption\EncryptResult;
use App\Base\Database\Services\Backup\Manifest;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Generate a random base64:-prefixed 32-byte APP_KEY and configure it.
 */
function akSetupAppKey(): string
{
    $key = 'base64:'.base64_encode(random_bytes(32));
    config(['app.key' => $key]);

    return $key;
}

/**
 * Create a temp file with $content, return its path.
 */
function akTempFile(string $content = ''): string
{
    $path = sys_get_temp_dir().'/blb-akenc-'.bin2hex(random_bytes(4));
    file_put_contents($path, $content);

    return $path;
}

/**
 * Build a minimal Manifest DTO with the envelope fields from an EncryptResult.
 */
function akManifestFromResult(EncryptResult $result): Manifest
{
    return new Manifest(
        backupId: 'test',
        driver: 'sqlite',
        encryptionMode: 'app-key',
        sourceLabel: 'test',
        engineVersion: '3.0',
        appEnvironment: 'testing',
        artifactPath: 'test.bak.enc',
        sizeBytes: 0,
        sha256: str_repeat('a', 64),
        startedAt: now()->toIso8601String(),
        finishedAt: now()->toIso8601String(),
        trigger: 'test',
        status: 'success',
        wrappedDek: $result->wrappedDek,
        dekNonce: $result->dekNonce,
        kekFingerprint: $result->kekFingerprint,
    );
}

it('round-trips a small plaintext through encrypt then decrypt', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    $plain = akTempFile('Hello, Belimbing! This is a test payload.');
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));
    $decrypted = sys_get_temp_dir().'/blb-akenc-d-'.bin2hex(random_bytes(4));

    try {
        $result = $enc->encryptFile($plain, $cipher);

        expect($result)->toBeInstanceOf(EncryptResult::class);
        expect($result->wrappedDek)->not->toBeNull();
        expect($result->dekNonce)->not->toBeNull();
        expect($result->kekFingerprint)->not->toBeNull();

        $manifest = akManifestFromResult($result);
        $enc->decryptFile($cipher, $decrypted, $manifest);

        expect(file_get_contents($decrypted))->toBe('Hello, Belimbing! This is a test payload.');
    } finally {
        @unlink($plain);
        @unlink($cipher);
        @unlink($decrypted);
    }
});

it('round-trips an empty plaintext', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    $plain = akTempFile('');
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));
    $decrypted = sys_get_temp_dir().'/blb-akenc-d-'.bin2hex(random_bytes(4));

    try {
        $result = $enc->encryptFile($plain, $cipher);
        $manifest = akManifestFromResult($result);
        $enc->decryptFile($cipher, $decrypted, $manifest);

        expect(file_get_contents($decrypted))->toBe('');
    } finally {
        @unlink($plain);
        @unlink($cipher);
        @unlink($decrypted);
    }
});

it('round-trips a multi-chunk payload (>64 KiB)', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    // 200 KiB crosses three 65536-byte chunk boundaries.
    $plaintext = str_repeat('BELIMBING', 22850); // 9 bytes × 22850 = 205650 bytes
    $plain = akTempFile($plaintext);
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));
    $decrypted = sys_get_temp_dir().'/blb-akenc-d-'.bin2hex(random_bytes(4));

    try {
        $result = $enc->encryptFile($plain, $cipher);
        $manifest = akManifestFromResult($result);
        $enc->decryptFile($cipher, $decrypted, $manifest);

        expect(file_get_contents($decrypted))->toBe($plaintext);
    } finally {
        @unlink($plain);
        @unlink($cipher);
        @unlink($decrypted);
    }
});

it('produces ciphertext that differs from the plaintext', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    $plaintext = 'SQLite format 3'.str_repeat('x', 200);
    $plain = akTempFile($plaintext);
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));

    try {
        $enc->encryptFile($plain, $cipher);
        $cipherBytes = file_get_contents($cipher);
        expect($cipherBytes)->not->toBe($plaintext);
        expect(str_contains((string) $cipherBytes, 'SQLite format 3'))->toBeFalse();
    } finally {
        @unlink($plain);
        @unlink($cipher);
    }
});

it('decodes a base64:-prefixed APP_KEY correctly', function (): void {
    $rawKey = random_bytes(32);
    config(['app.key' => 'base64:'.base64_encode($rawKey)]);

    $enc = new AppKeyEncryption;
    $enc->ensureReady(); // Must not throw.

    expect(true)->toBeTrue(); // Assertion is "no exception thrown".
});

it('throws BackupException from ensureReady() when APP_KEY is invalid', function (): void {
    config(['app.key' => 'not-a-valid-key']);

    $enc = new AppKeyEncryption;

    expect(fn () => $enc->ensureReady())->toThrow(BackupException::class);
});

it('throws BackupException from ensureReady() when APP_KEY decodes to wrong length', function (): void {
    config(['app.key' => 'base64:'.base64_encode('only-16-bytes!!!')]); // 16 bytes, not 32

    $enc = new AppKeyEncryption;

    expect(fn () => $enc->ensureReady())->toThrow(BackupException::class);
});

it('throws BackupException when decrypting with a wrong APP_KEY', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    $plain = akTempFile('sensitive data here');
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));
    $decrypted = sys_get_temp_dir().'/blb-akenc-d-'.bin2hex(random_bytes(4));

    try {
        $result = $enc->encryptFile($plain, $cipher);
        $manifest = akManifestFromResult($result);

        // Rotate to a different APP_KEY — decryption should fail.
        akSetupAppKey();

        expect(fn () => $enc->decryptFile($cipher, $decrypted, $manifest))
            ->toThrow(BackupException::class);
    } finally {
        @unlink($plain);
        @unlink($cipher);
        @unlink($decrypted);
    }
});

it('throws BackupException when decryptFile() is called without a manifest', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    expect(fn () => $enc->decryptFile('/dev/null', '/dev/null', null))
        ->toThrow(BackupException::class);
});

it('currentFingerprint() returns the same value for the same APP_KEY', function (): void {
    akSetupAppKey();

    $fp1 = AppKeyEncryption::currentFingerprint();
    $fp2 = AppKeyEncryption::currentFingerprint();

    expect($fp1)->not->toBeNull();
    expect($fp1)->toBe($fp2);
});

it('currentFingerprint() returns different values for different APP_KEYs', function (): void {
    akSetupAppKey();
    $fp1 = AppKeyEncryption::currentFingerprint();

    akSetupAppKey(); // Different random key.
    $fp2 = AppKeyEncryption::currentFingerprint();

    expect($fp1)->not->toBe($fp2);
});

it('kek_fingerprint in EncryptResult matches currentFingerprint()', function (): void {
    akSetupAppKey();
    $enc = new AppKeyEncryption;

    $plain = akTempFile('fingerprint check');
    $cipher = sys_get_temp_dir().'/blb-akenc-c-'.bin2hex(random_bytes(4));

    try {
        $result = $enc->encryptFile($plain, $cipher);

        expect($result->kekFingerprint)->toBe(AppKeyEncryption::currentFingerprint());
    } finally {
        @unlink($plain);
        @unlink($cipher);
    }
});
