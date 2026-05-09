<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Encryption;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Manifest;
use SodiumException;

/**
 * Envelope encryption keyed from APP_KEY.
 *
 * Key derivation:
 *   raw_key = base64_decode(after stripping 'base64:' prefix from APP_KEY) — must be 32 bytes.
 *   KEK     = HKDF-SHA-256(raw_key, length=32, info="blb-backup-kek-v1", salt="\0"×32)
 *
 * Per-artifact DEK:
 *   dek         = random_bytes(32)
 *   dek_nonce   = random_bytes(24)
 *   wrapped_dek = sodium_crypto_secretbox(dek, dek_nonce, KEK) — 48 bytes (32 + 16 MAC)
 *
 * KEK fingerprint (non-secret; used to detect APP_KEY drift without exposing the KEK):
 *   kek_fingerprint = HKDF-SHA-256(KEK, length=8, info="blb-backup-kek-fp-v1") — 8 bytes
 *
 * On-disk artifact layout:
 *   header  24 bytes  (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES)
 *   chunks  repeated: [uint32 BE length (4 bytes)][ciphertext]; last chunk tagged FINAL.
 *
 * Manifest fields (base64-encoded) written by encryptFile():
 *   wrapped_dek     48 raw bytes
 *   dek_nonce       24 raw bytes
 *   kek_fingerprint  8 raw bytes
 *
 * ⚠️  Losing APP_KEY means losing every app-key-mode backup — it is the sole
 *     unwrap key. Back it up separately per docs/runbooks/database-backup.md.
 */
final class AppKeyEncryption implements EncryptionMode
{
    private const PLAINTEXT_CHUNK_SIZE = 65536;

    public function name(): string
    {
        return 'app-key';
    }

    public function extension(): string
    {
        return '.enc';
    }

    public function ensureReady(): void
    {
        if (! extension_loaded('sodium')) {
            throw BackupException::toolingMissing('ext-sodium', 'PHP sodium extension is required for app-key encryption');
        }

        $this->decodeAppKey(); // throws BackupException if invalid
    }

    public function encryptFile(string $sourcePath, string $destinationPath): EncryptResult
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
            $dek = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);

            $this->fwriteAll($out, $header, $destinationPath);

            // Read-ahead: hold one chunk so the last chunk is tagged FINAL.
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

            // Wrap the DEK under the KEK derived from APP_KEY.
            $rawKey = $this->decodeAppKey();
            $kek = self::deriveKekFromRaw($rawKey);
            $dekNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $wrappedDek = sodium_crypto_secretbox($dek, $dekNonce, $kek);
            $fingerprint = self::fingerprintFromKek($kek);

            sodium_memzero($dek);
            sodium_memzero($rawKey);
            sodium_memzero($kek);

            return new EncryptResult(
                wrappedDek: base64_encode($wrappedDek),
                dekNonce: base64_encode($dekNonce),
                kekFingerprint: base64_encode($fingerprint),
            );
        } catch (SodiumException $e) {
            @unlink($destinationPath);
            throw BackupException::encryptionFailed($e->getMessage(), $e);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $sourcePath, string $destinationPath, ?Manifest $manifest = null): void
    {
        $dek = $this->unwrapDekForArtifact($manifest);

        if (! is_file($sourcePath)) {
            sodium_memzero($dek);
            throw BackupException::artifactNotFound($sourcePath);
        }

        if (file_exists($destinationPath)) {
            sodium_memzero($dek);
            throw BackupException::restoreFailed("Destination already exists: {$destinationPath}");
        }

        $in = @fopen($sourcePath, 'rb');
        if ($in === false) {
            sodium_memzero($dek);
            throw BackupException::decryptionFailed("Could not open artifact: {$sourcePath}");
        }

        $out = @fopen($destinationPath, 'wb');
        if ($out === false) {
            fclose($in);
            sodium_memzero($dek);
            throw BackupException::decryptionFailed("Could not open destination: {$destinationPath}");
        }
        @chmod($destinationPath, 0600);

        try {
            $this->pullSecretstreamFileToPlaintext($in, $out, $dek, $sourcePath, $destinationPath);
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

    /**
     * @throws BackupException
     */
    private function unwrapDekForArtifact(?Manifest $manifest): string
    {
        if ($manifest === null || $manifest->wrappedDek === null || $manifest->dekNonce === null) {
            throw BackupException::decryptionFailed(
                'app-key decryption requires manifest context (wrapped_dek, dek_nonce). Pass the sidecar manifest to decryptFile().'
            );
        }

        $wrappedDek = base64_decode($manifest->wrappedDek, strict: true);
        $dekNonce = base64_decode($manifest->dekNonce, strict: true);

        if ($wrappedDek === false || $dekNonce === false) {
            throw BackupException::decryptionFailed('Manifest wrapped_dek or dek_nonce is not valid base64');
        }

        try {
            $rawKey = $this->decodeAppKey();
            $kek = self::deriveKekFromRaw($rawKey);
            sodium_memzero($rawKey);

            $dek = sodium_crypto_secretbox_open($wrappedDek, $dekNonce, $kek);
            sodium_memzero($kek);
        } catch (SodiumException $e) {
            throw BackupException::decryptionFailed('DEK unwrap failed: '.$e->getMessage(), $e);
        }

        if ($dek === false) {
            throw BackupException::decryptionFailed('DEK authentication failed; wrong APP_KEY or tampered manifest');
        }

        return $dek;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     *
     * @throws BackupException
     */
    private function pullSecretstreamFileToPlaintext($in, $out, string $dek, string $sourcePath, string $destinationPath): void
    {
        $headerBytes = @fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (! is_string($headerBytes) || strlen($headerBytes) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw BackupException::decryptionFailed("Artifact too short or unreadable: {$sourcePath}");
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($headerBytes, $dek);
        sodium_memzero($dek);

        $sawFinal = false;

        while (true) {
            $lenBytes = @fread($in, 4);
            if ($lenBytes === false || $lenBytes === '') {
                break; // EOF
            }
            if (strlen($lenBytes) !== 4) {
                throw BackupException::decryptionFailed("Truncated chunk length in {$sourcePath}");
            }

            $chunkLen = (int) unpack('N', $lenBytes)[1];
            $cipher = '';
            while (strlen($cipher) < $chunkLen) {
                $part = @fread($in, $chunkLen - strlen($cipher));
                if ($part === false || $part === '') {
                    throw BackupException::decryptionFailed("Truncated chunk body in {$sourcePath}");
                }
                $cipher .= $part;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($result === false) {
                throw BackupException::decryptionFailed('Chunk decryption failed; artifact corrupt or tampered');
            }

            [$plain, $tag] = $result;
            $this->fwriteAll($out, $plain, $destinationPath);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        if (! $sawFinal) {
            throw BackupException::decryptionFailed('Artifact truncated: TAG_FINAL not reached');
        }
    }

    /**
     * Compute the KEK fingerprint for the current APP_KEY.
     * Returns null if APP_KEY is absent or cannot be decoded to 32 bytes.
     * Safe to call in preflight contexts — never throws.
     */
    public static function currentFingerprint(): ?string
    {
        try {
            $appKey = (string) config('app.key', '');
            if ($appKey === '') {
                return null;
            }

            $rawKey = str_starts_with($appKey, 'base64:')
                ? base64_decode(substr($appKey, 7), strict: true)
                : $appKey;

            if ($rawKey === false || strlen($rawKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return null;
            }

            $kek = self::deriveKekFromRaw($rawKey);

            return base64_encode(self::fingerprintFromKek($kek));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Derive a KEK from 32 raw key bytes using HKDF-SHA-256.
     * Caller is responsible for zeroing the returned value after use.
     */
    public static function deriveKekFromRaw(string $rawKey): string
    {
        return hash_hkdf('sha256', $rawKey, 32, 'blb-backup-kek-v1', str_repeat("\0", 32));
    }

    /**
     * Compute the 8-byte fingerprint from KEK bytes using HKDF-SHA-256.
     * Not secret; used to detect APP_KEY drift.
     */
    public static function fingerprintFromKek(string $kek): string
    {
        return hash_hkdf('sha256', $kek, 8, 'blb-backup-kek-fp-v1');
    }

    private function decodeAppKey(): string
    {
        $appKey = (string) config('app.key', '');

        $rawKey = str_starts_with($appKey, 'base64:')
            ? base64_decode(substr($appKey, 7), strict: true)
            : $appKey;

        if ($rawKey === false || strlen($rawKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw BackupException::configurationInvalid(
                'APP_KEY does not decode to 32 bytes. Re-generate with: php artisan blb:key:rotate'
            );
        }

        return $rawKey;
    }

    /**
     * @param  resource  $handle
     */
    private function readChunk($handle, string $path): string
    {
        $data = @fread($handle, self::PLAINTEXT_CHUNK_SIZE);
        if ($data === false) {
            throw BackupException::dumpFailed("Read error on {$path}");
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function fwriteAll($handle, string $data, string $path): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($handle, substr($data, $written));
            if ($result === false || $result === 0) {
                throw BackupException::storageFailed("Write error on {$path}");
            }
            $written += $result;
        }
    }

    /**
     * @param  resource  $handle
     */
    private function writeChunk($handle, string $cipher, string $path): void
    {
        $this->fwriteAll($handle, pack('N', strlen($cipher)), $path);
        $this->fwriteAll($handle, $cipher, $path);
    }
}
