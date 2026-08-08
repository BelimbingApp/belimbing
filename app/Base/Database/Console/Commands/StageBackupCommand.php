<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Manifest;
use Illuminate\Console\Command;
use Throwable;

final class StageBackupCommand extends Command
{
    protected $signature = 'blb:db:backup:stage
                            {artifact : Local path to the backup artifact}
                            {manifest : Local path to its sidecar manifest}
                            {output : New local path for the decrypted plaintext artifact}';

    protected $description = 'Decrypt a backup into a new local staging file without connecting to a database';

    public function handle(EncryptionModeRegistry $modes): int
    {
        $artifact = $this->absolutePath((string) $this->argument('artifact'));
        $manifestPath = $this->absolutePath((string) $this->argument('manifest'));
        $output = $this->absolutePath((string) $this->argument('output'));
        $temporaryDirectory = dirname($output).'/.blb-stage-'.bin2hex(random_bytes(16));
        $temporaryOutput = $temporaryDirectory.'/artifact';
        $temporaryDirectoryIdentity = null;
        $outputIdentity = null;

        if (file_exists($output)) {
            $this->components->error("Output already exists; refusing to overwrite: {$output}");

            return self::FAILURE;
        }

        try {
            if (! @mkdir($temporaryDirectory, 0700)) {
                throw BackupException::restoreFailed('Could not create a private staging directory beside the output.');
            }
            $temporaryDirectoryIdentity = @stat($temporaryDirectory);

            $data = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw BackupException::artifactCorrupt('Manifest is not a JSON object.');
            }

            $manifest = Manifest::fromArray($data);
            if (! is_file($artifact) || ! hash_equals($manifest->sha256, (string) hash_file('sha256', $artifact))) {
                throw BackupException::artifactCorrupt('Artifact SHA-256 does not match the sidecar manifest.');
            }

            $mode = $modes->resolve($manifest->encryptionMode, config('backup'));
            $mode->ensureReady();
            $mode->decryptFile($artifact, $temporaryOutput, $manifest);

            $destination = @fopen($output, 'xb');
            if ($destination === false) {
                throw BackupException::restoreFailed("Output already exists; refusing to overwrite: {$output}");
            }
            $outputIdentity = fstat($destination);

            try {
                $source = @fopen($temporaryOutput, 'rb');
                if ($source === false) {
                    throw BackupException::restoreFailed('Could not read the staged plaintext artifact.');
                }
                try {
                    if (stream_copy_to_stream($source, $destination) === false) {
                        throw BackupException::restoreFailed("Could not publish the staged artifact to {$output}");
                    }
                } finally {
                    fclose($source);
                }
            } finally {
                fclose($destination);
            }
            @chmod($output, 0600);
        } catch (Throwable $e) {
            $this->unlinkIfSameFile($output, $outputIdentity);
            $this->components->error('Backup staging failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($this->isSameFile($temporaryDirectory, $temporaryDirectoryIdentity)) {
                @unlink($temporaryOutput);
                @rmdir($temporaryDirectory);
            }
        }

        $this->components->info('Backup staged without connecting to a database.');
        $this->components->twoColumnDetail('Output', $output);

        return self::SUCCESS;
    }

    /** @param array<string|int, mixed>|false|null $identity */
    private function unlinkIfSameFile(string $path, array|false|null $identity): void
    {
        if (! is_array($identity)) {
            return;
        }

        if ($this->isSameFile($path, $identity)) {
            @unlink($path);
        }
    }

    /** @param array<string|int, mixed>|false|null $identity */
    private function isSameFile(string $path, array|false|null $identity): bool
    {
        if (! is_array($identity)) {
            return false;
        }

        $current = @stat($path);

        return is_array($current)
            && ($current['dev'] ?? null) === ($identity['dev'] ?? null)
            && ($current['ino'] ?? null) === ($identity['ino'] ?? null);
    }

    private function absolutePath(string $path): string
    {
        $isWindowsAbsolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');

        return str_starts_with($path, DIRECTORY_SEPARATOR) || $isWindowsAbsolute ? $path : base_path($path);
    }
}
