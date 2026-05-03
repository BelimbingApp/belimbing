<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Encryption;

use App\Base\Database\Exceptions\BackupException;
use SodiumException;

/**
 * Passphrase-based authenticated encryption using libsodium.
 *
 * - Key derivation: Argon2id (sodium_crypto_pwhash) with INTERACTIVE limits.
 * - Stream cipher: XChaCha20-Poly1305 secretstream.
 *
 * On-disk artifact layout:
 *
 *   magic   8 bytes  "BLBPASS\x01"
 *   salt    16 bytes (SODIUM_CRYPTO_PWHASH_SALTBYTES)
 *   header  24 bytes (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *   chunks  repeated [uint32 BE length][ciphertext]; final chunk uses
 *           TAG_FINAL so truncation is detected.
 *
 * Plaintext chunk size is 64 KiB; ciphertext adds 17 bytes (ABYTES) per chunk.
 */
final class PassphraseEncryption implements EncryptionMode
{
    private const MAGIC = "BLBPASS\x01";

    private const PLAINTEXT_CHUNK_SIZE = 65536;

    private const MAX_CIPHERTEXT_CHUNK_SIZE = self::PLAINTEXT_CHUNK_SIZE + 64;

    public function __construct(private readonly string $passphrase) {}

    public function name(): string
    {
        return 'passphrase';
    }

    public function extension(): string
    {
        return '.enc';
    }

    public function ensureReady(): void
    {
        if (! extension_loaded('sodium')) {
            throw BackupException::toolingMissing('ext-sodium', 'PHP sodium extension is required for passphrase encryption');
        }

        if ($this->passphrase === '') {
            throw BackupException::configurationInvalid('Passphrase is empty; set the configured BACKUP_PASSPHRASE env var');
        }
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->ensureReady();

        if (! is_file($sourcePath)) {
            throw BackupException::dumpFailed("Source dump missing: {$sourcePath}");
        }

        if (file_exists($destinationPath)) {
            throw BackupException::storageFailed("Destination already exists: {$destinationPath}");
        }

        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw BackupException::dumpFailed("Could not open source dump: {$sourcePath}");
        }

        $out = @fopen($destinationPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw BackupException::storageFailed("Could not open destination: {$destinationPath}");
        }
        @chmod($destinationPath, 0600);

        try {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = $this->deriveKey($salt);

            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

            $this->fwriteAll($out, self::MAGIC, $destinationPath);
            $this->fwriteAll($out, $salt, $destinationPath);
            $this->fwriteAll($out, $header, $destinationPath);

            // Read-ahead pattern: hold one chunk back so we can tag the
            // last chunk with TAG_FINAL.
            $buffer = $this->readChunk($in, $sourcePath);

            while (true) {
                $next = $this->readChunk($in, $sourcePath);

                if ($next === '') {
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $buffer,
                        '',
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
                    );
                    $this->writeChunk($out, $cipher, $destinationPath);
                    break;
                }

                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $buffer,
                    '',
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                );
                $this->writeChunk($out, $cipher, $destinationPath);

                $buffer = $next;
            }

            sodium_memzero($key);
        } catch (SodiumException $e) {
            @unlink($destinationPath);
            throw BackupException::encryptionFailed($e->getMessage(), $e);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->ensureReady();

        if (! is_file($sourcePath)) {
            throw BackupException::artifactNotFound($sourcePath);
        }

        if (file_exists($destinationPath)) {
            throw BackupException::restoreFailed("Destination already exists: {$destinationPath}");
        }

        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            throw BackupException::decryptionFailed("Could not open artifact: {$sourcePath}");
        }

        $out = @fopen($destinationPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw BackupException::restoreFailed("Could not open plaintext destination: {$destinationPath}");
        }
        @chmod($destinationPath, 0600);

        try {
            $magic = $this->freadExact($in, strlen(self::MAGIC));
            if ($magic !== self::MAGIC) {
                throw BackupException::artifactCorrupt('Bad magic header; not a BLB passphrase artifact or wrong version');
            }

            $salt = $this->freadExact($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = $this->freadExact($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            $key = $this->deriveKey($salt);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            sodium_memzero($key);

            $sawFinal = false;

            while (true) {
                $lenBytes = fread($in, 4);
                if ($lenBytes === '' || $lenBytes === false) {
                    break;
                }
                if (strlen($lenBytes) !== 4) {
                    throw BackupException::artifactCorrupt('Truncated chunk length');
                }

                $unpacked = unpack('N', $lenBytes);
                if ($unpacked === false) {
                    throw BackupException::artifactCorrupt('Bad chunk length encoding');
                }
                $len = $unpacked[1];

                if ($len <= 0 || $len > self::MAX_CIPHERTEXT_CHUNK_SIZE) {
                    throw BackupException::artifactCorrupt("Implausible chunk length: {$len}");
                }

                $cipher = $this->freadExact($in, $len);

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw BackupException::decryptionFailed('Authentication failed; wrong passphrase or tampered artifact');
                }

                [$plain, $tag] = $result;

                if ($plain !== '') {
                    $this->fwriteAll($out, $plain, $destinationPath);
                }

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                    break;
                }
            }

            if (! $sawFinal) {
                throw BackupException::artifactCorrupt('Stream ended without final tag (possible truncation)');
            }
        } catch (SodiumException $e) {
            @unlink($destinationPath);
            throw BackupException::decryptionFailed($e->getMessage(), $e);
        } catch (BackupException $e) {
            @unlink($destinationPath);
            throw $e;
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function deriveKey(string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $this->passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    private function writeChunk($handle, string $cipher, string $destPath): void
    {
        $this->fwriteAll($handle, pack('N', strlen($cipher)), $destPath);
        $this->fwriteAll($handle, $cipher, $destPath);
    }

    private function fwriteAll($handle, string $data, string $destPath): void
    {
        $remaining = strlen($data);
        $offset = 0;
        while ($remaining > 0) {
            $written = fwrite($handle, substr($data, $offset, $remaining));
            if ($written === false || $written === 0) {
                throw BackupException::storageFailed("Write failed to {$destPath}");
            }
            $offset += $written;
            $remaining -= $written;
        }
    }

    private function readChunk($handle, string $sourcePath): string
    {
        $buffer = '';
        $remaining = self::PLAINTEXT_CHUNK_SIZE;

        while ($remaining > 0) {
            if (feof($handle)) {
                break;
            }
            $read = fread($handle, $remaining);
            if ($read === false) {
                throw BackupException::dumpFailed("Read error from source dump: {$sourcePath}");
            }
            if ($read === '') {
                break;
            }
            $buffer .= $read;
            $remaining -= strlen($read);
        }

        return $buffer;
    }

    private function freadExact($handle, int $bytes): string
    {
        $buffer = '';
        $remaining = $bytes;
        while ($remaining > 0) {
            $chunk = fread($handle, $remaining);
            if ($chunk === false || $chunk === '') {
                throw BackupException::artifactCorrupt("Unexpected end of artifact (needed {$bytes} bytes)");
            }
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $buffer;
    }
}
