<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataSharePolicyException;
use App\Base\Settings\Contracts\SettingsService;

final class DataShareSettings
{
    private const STORAGE_PATHS = [
        'data_share.outgoing_path_prefix' => 'data-share/outgoing',
        'data_share.receiving_path_prefix' => 'data-share/receiving',
        'data_share.incoming_path_prefix' => 'data-share/incoming',
        'data_share.path_prefix' => 'data-share/diagnostics',
    ];

    /** @var array<string, mixed> */
    private array $resolved = [];

    public function __construct(private readonly SettingsService $settings) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->value($key, $default);

        if (! is_scalar($value) && $value !== null) {
            throw DataSharePolicyException::invalidSetting($key);
        }

        return trim((string) $value);
    }

    public function integer(
        string $key,
        int $default,
        int $minimum = 0,
        int $maximum = PHP_INT_MAX,
    ): int {
        $value = filter_var($this->value($key, $default), FILTER_VALIDATE_INT);

        if ($value === false || $value < $minimum || $value > $maximum) {
            throw DataSharePolicyException::invalidSetting($key);
        }

        return $value;
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->value($key, '');

        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            throw DataSharePolicyException::invalidSetting($key);
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw DataSharePolicyException::invalidSetting($key);
            }

            $item = trim($item);

            if ($item !== '') {
                $strings[] = $item;
            }
        }

        return array_values(array_unique($strings));
    }

    public function disk(): string
    {
        $disk = $this->string('data_share.disk', 'local');

        if ($disk === '' || preg_match('/^[A-Za-z0-9._-]+$/', $disk) !== 1) {
            throw DataSharePolicyException::invalidSetting('data_share.disk');
        }

        return $disk;
    }

    public function pathPrefix(string $key, string $default): string
    {
        $path = $this->normalizedPath($key, $default);

        if (array_key_exists($key, self::STORAGE_PATHS)) {
            $this->assertStoragePathsDoNotOverlap($this->storagePathsFor($key, $path));
        }

        return $path;
    }

    /** @return array<string, string> */
    private function storagePathsFor(string $key, string $path): array
    {
        $paths = [];

        foreach (self::STORAGE_PATHS as $settingKey => $settingDefault) {
            $paths[$settingKey] = $settingKey === $key
                ? $path
                : $this->normalizedPath($settingKey, $settingDefault);
        }

        return $paths;
    }

    /** @param array<string, string> $paths */
    private function assertStoragePathsDoNotOverlap(array $paths): void
    {
        foreach ($paths as $leftKey => $left) {
            foreach ($paths as $rightKey => $right) {
                if ($leftKey !== $rightKey && ($left === $right || str_starts_with($left, $right.'/'))) {
                    throw DataSharePolicyException::invalidSetting($leftKey);
                }
            }
        }
    }

    private function normalizedPath(string $key, string $default): string
    {
        $path = trim($this->string($key, $default), '/');
        $segments = explode('/', $path);
        $invalidSegment = false;

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $invalidSegment = true;
                break;
            }
        }

        if ($path === ''
            || str_contains($path, '\\')
            || $invalidSegment
            || preg_match('/^[A-Za-z0-9._\/-]+$/', $path) !== 1) {
            throw DataSharePolicyException::invalidSetting($key);
        }

        return $path;
    }

    private function value(string $key, mixed $default): mixed
    {
        if (! array_key_exists($key, $this->resolved)) {
            $value = $this->settings->get($key, $default);
            $this->resolved[$key] = $value ?? $default;
        }

        return $this->resolved[$key];
    }
}
