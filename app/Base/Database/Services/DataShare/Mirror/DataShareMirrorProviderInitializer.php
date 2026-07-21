<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareMirrorException;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Throwable;

final readonly class DataShareMirrorProviderInitializer
{
    public function __construct(private DataShareMirrorConnectionManager $connections) {}

    public function initialize(): void
    {
        try {
            $this->initializeProvider();
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::initializationFailed($exception);
        }
    }

    private function initializeProvider(): void
    {
        $connection = $this->connections->mirrorForInitialization();
        $exitCode = Artisan::call('migrate', [
            '--database' => DataShareMirrorConnectionManager::CONNECTION,
            '--force' => true,
        ]);

        if ($exitCode !== Command::SUCCESS) {
            throw DataShareMirrorException::initializationFailed();
        }

        $now = now();
        $this->putSetting($connection, 'data_share.instance.id', 'mirror-'.Str::lower((string) Str::uuid()), $now);
        $this->putSetting($connection, 'data_share.instance.name', $this->connections->provider()->label().' development mirror', $now);
        $this->putSetting($connection, 'data_share.instance.role', DataShareInstanceRole::Development->value, $now);
        $this->connections->purge();

        if (! $this->connections->status()->available) {
            throw DataShareMirrorException::initializationFailed();
        }
    }

    private function putSetting(Connection $connection, string $key, string $value, mixed $now): void
    {
        $attributes = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'is_encrypted' => false,
            'updated_at' => $now,
        ];
        $query = $connection->table('base_settings')
            ->where('key', $key)
            ->whereNull('scope_type')
            ->whereNull('scope_id');

        if ($query->exists()) {
            $query->update($attributes);

            return;
        }

        $connection->table('base_settings')->insert(array_merge($attributes, [
            'key' => $key,
            'scope_type' => null,
            'scope_id' => null,
            'created_at' => $now,
        ]));
    }
}
