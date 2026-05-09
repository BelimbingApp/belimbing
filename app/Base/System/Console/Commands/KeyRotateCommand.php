<?php
namespace App\Base\System\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Safe APP_KEY rotation sequence.
 *
 * This command replaces the dangerous raw `php artisan key:generate` workflow
 * for sites that use app-key backup encryption. It:
 *
 *   1. Captures the old APP_KEY.
 *   2. Generates a new 32-byte random key, writes it to .env.
 *   3. Updates config('app.key') in the running process so that
 *      the subsequent rekey command can derive the new KEK immediately.
 *   4. Calls `blb:db:backup:rekey --old-key=<oldKey> --commit` to
 *      re-wrap all app-key manifest DEKs under the new KEK.
 *
 * On rekey failure the old key is printed to stderr so the operator can
 * restore .env manually. Always store the old key in a safe location
 * before rotation — if you lose both old and new keys, backups are
 * irrecoverable.
 */
#[AsCommand(name: 'blb:key:rotate')]
final class KeyRotateCommand extends Command
{
    protected $signature = 'blb:key:rotate';

    protected $description = 'Safely rotate APP_KEY and re-wrap all backup DEKs';

    public function handle(): int
    {
        $oldKey = (string) config('app.key', '');

        if ($oldKey === '') {
            $this->components->error('APP_KEY is not set. Set a valid APP_KEY before rotating.');

            return self::FAILURE;
        }

        $envPath = $this->laravel->environmentFilePath();

        if (! is_file($envPath)) {
            $this->components->error('.env file not found at '.$envPath.'. Cannot write new APP_KEY.');

            return self::FAILURE;
        }

        $envContent = file_get_contents($envPath);
        if ($envContent === false) {
            $this->components->error('Could not read .env file.');

            return self::FAILURE;
        }

        // Generate new key.
        $newRaw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $newKey = 'base64:'.base64_encode($newRaw);
        sodium_memzero($newRaw);

        // Write new key to .env.
        $updated = preg_replace('/^APP_KEY=.*/m', 'APP_KEY='.$newKey, $envContent, limit: 1, count: $count);
        if ($updated === null || $count === 0) {
            $this->components->error('APP_KEY line not found in .env. Add "APP_KEY=" to .env and re-run.');

            return self::FAILURE;
        }

        if (file_put_contents($envPath, $updated) === false) {
            $this->components->error('Could not write updated .env file.');

            return self::FAILURE;
        }

        // Update the running process so the rekey command uses the new KEK.
        config(['app.key' => $newKey]);

        $this->components->info('APP_KEY updated in .env.');
        $this->newLine();

        // Re-wrap all backup DEKs using the old key to open them.
        $exitCode = $this->call('blb:db:backup:rekey', [
            '--old-key' => $oldKey,
            '--commit' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            $this->newLine();
            $this->components->error('Rekey step failed. Your .env has already been updated to the new APP_KEY.');
            $this->components->error('Old APP_KEY (keep safe!): '.$oldKey);
            $this->components->error('Run: php artisan blb:db:backup:rekey --old-key="<old>" --commit');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Key rotation complete. All backup DEKs are now wrapped under the new APP_KEY.');
        $this->components->warn('Clear any PHP OPcache or process caches before the next request cycle.');

        return self::SUCCESS;
    }
}
