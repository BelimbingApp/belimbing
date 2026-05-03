<?php

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Encryption\PassphraseEncryption;

beforeEach(function () {
    $this->workdir = sys_get_temp_dir().'/blb-passphrase-test-'.bin2hex(random_bytes(4));
    mkdir($this->workdir, 0700, true);
});

afterEach(function () {
    if (is_dir($this->workdir)) {
        foreach (glob($this->workdir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->workdir);
    }
});

function ppeWrite(string $path, string $bytes): void
{
    file_put_contents($path, $bytes);
}

it('round-trips small payload through encrypt then decrypt', function (): void {
    $plain = $this->workdir.'/plain.in';
    $cipher = $this->workdir.'/out.enc';
    $back = $this->workdir.'/plain.out';

    ppeWrite($plain, 'hello belimbing backup pipeline');

    $enc = new PassphraseEncryption('correct horse battery staple');
    $enc->encryptFile($plain, $cipher);

    expect(filesize($cipher))->toBeGreaterThan(filesize($plain));
    expect(substr(file_get_contents($cipher), 0, 8))->toBe("BLBPASS\x01");

    $enc->decryptFile($cipher, $back);
    expect(file_get_contents($back))->toBe(file_get_contents($plain));
});

it('round-trips multi-chunk payload (over 64 KiB)', function (): void {
    $plain = $this->workdir.'/plain.in';
    $cipher = $this->workdir.'/out.enc';
    $back = $this->workdir.'/plain.out';

    // 256 KiB of pseudo-random bytes — spans multiple secretstream chunks.
    ppeWrite($plain, random_bytes(256 * 1024));

    $enc = new PassphraseEncryption('correct horse battery staple');
    $enc->encryptFile($plain, $cipher);
    $enc->decryptFile($cipher, $back);

    expect(hash_file('sha256', $back))->toBe(hash_file('sha256', $plain));
});

it('round-trips empty plaintext', function (): void {
    $plain = $this->workdir.'/empty.in';
    $cipher = $this->workdir.'/empty.enc';
    $back = $this->workdir.'/empty.out';

    ppeWrite($plain, '');

    $enc = new PassphraseEncryption('p');
    $enc->encryptFile($plain, $cipher);
    $enc->decryptFile($cipher, $back);

    expect(file_get_contents($back))->toBe('');
});

it('rejects wrong passphrase with authentication failure', function (): void {
    $plain = $this->workdir.'/plain.in';
    $cipher = $this->workdir.'/out.enc';
    $back = $this->workdir.'/plain.out';

    ppeWrite($plain, 'sensitive');

    (new PassphraseEncryption('right'))->encryptFile($plain, $cipher);

    expect(fn () => (new PassphraseEncryption('wrong'))->decryptFile($cipher, $back))
        ->toThrow(BackupException::class, 'Authentication failed');

    // Decryption must not leave a partial plaintext file behind.
    expect(file_exists($back))->toBeFalse();
});

it('detects ciphertext tampering', function (): void {
    $plain = $this->workdir.'/plain.in';
    $cipher = $this->workdir.'/out.enc';
    $back = $this->workdir.'/plain.out';

    ppeWrite($plain, str_repeat('A', 1024));

    $enc = new PassphraseEncryption('pp');
    $enc->encryptFile($plain, $cipher);

    // Flip a byte deep in the ciphertext (after magic+salt+header).
    $bytes = file_get_contents($cipher);
    $offset = 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 8;
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
    file_put_contents($cipher, $bytes);

    expect(fn () => $enc->decryptFile($cipher, $back))
        ->toThrow(BackupException::class);
});

it('detects truncation by missing TAG_FINAL', function (): void {
    $plain = $this->workdir.'/plain.in';
    $cipher = $this->workdir.'/out.enc';
    $back = $this->workdir.'/plain.out';

    // Force two chunks: 80 KiB > one chunk so we get MESSAGE then FINAL.
    ppeWrite($plain, random_bytes(80 * 1024));

    $enc = new PassphraseEncryption('pp');
    $enc->encryptFile($plain, $cipher);

    // Drop the last chunk by truncating to the first chunk's length boundary.
    // Find the second [uint32 length] and truncate just after the first cipher chunk.
    $bytes = file_get_contents($cipher);
    $headerSize = 8 + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
    $firstLen = unpack('N', substr($bytes, $headerSize, 4))[1];
    $truncated = substr($bytes, 0, $headerSize + 4 + $firstLen);
    file_put_contents($cipher, $truncated);

    expect(fn () => $enc->decryptFile($cipher, $back))
        ->toThrow(BackupException::class);
});

it('rejects empty passphrase as configuration error', function (): void {
    $enc = new PassphraseEncryption('');

    expect(fn () => $enc->ensureReady())
        ->toThrow(BackupException::class, 'Passphrase is empty');
});
