<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Exceptions\DataShareMirrorException;
use Illuminate\Database\ConfigurationUrlParser;

final class PostgresMirrorConnectionUrl
{
    /** @var list<string> */
    private const ALLOWED_QUERY_OPTIONS = [
        'connect_timeout',
        'sslmode',
    ];

    /** @return array<string, mixed> */
    public function parse(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)
            || ! is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || ! is_string($parts['user'] ?? null)
            || trim((string) $parts['user']) === ''
            || ! is_string($parts['path'] ?? null)
            || trim((string) $parts['path'], '/') === ''
            || isset($parts['fragment'])) {
            throw DataShareMirrorException::unavailable(__('The mirror connection URL is invalid.'));
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        foreach ($query as $key => $value) {
            if (! in_array($key, self::ALLOWED_QUERY_OPTIONS, true) || ! is_scalar($value)) {
                throw DataShareMirrorException::unavailable(__('The mirror connection URL contains an unsupported option.'));
            }
        }

        $configuration = (new ConfigurationUrlParser)->parseConfiguration($url);
        unset($configuration['url']);
        $sslmode = mb_strtolower((string) ($configuration['sslmode'] ?? 'require'));
        $allowedSslModes = ['require', 'verify-ca', 'verify-full'];
        if ((string) config('app.env') === 'testing') {
            $allowedSslModes[] = 'disable';
        }

        if (! in_array($sslmode, $allowedSslModes, true)) {
            throw DataShareMirrorException::unavailable(__('The mirror connection must require SSL.'));
        }

        $connectTimeout = filter_var($configuration['connect_timeout'] ?? 15, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 60],
        ]);
        if ($connectTimeout === false) {
            throw DataShareMirrorException::unavailable(__('The mirror connection timeout must be between 1 and 60 seconds.'));
        }

        return array_merge([
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $sslmode,
            'connect_timeout' => $connectTimeout,
        ], $configuration, [
            'connect_timeout' => $connectTimeout,
            'sslmode' => $sslmode,
        ]);
    }
}
