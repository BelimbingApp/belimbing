<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

trait ValidatesDataShareSettings
{
    private function validateMirrorUrl(string $formKey, string $value): void
    {
        $parts = parse_url($value);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if (! is_array($parts)
            || ! in_array($scheme, ['postgres', 'postgresql'], true)
            || ! is_string($parts['host'] ?? null)
            || trim((string) ($parts['host'] ?? '')) === ''
            || ! is_string($parts['user'] ?? null)
            || trim((string) ($parts['user'] ?? '')) === ''
            || isset($parts['fragment'])) {
            $this->fail($formKey, __('Enter a PostgreSQL connection URL with a scheme, user, and host, without a fragment.'));
        }
    }

    private function validateOfferUrls(): void
    {
        $key = $this->formKey('data_share.offers.base_urls');
        $raw = trim((string) ($this->values[$key] ?? ''));
        $urls = [];

        if ($raw !== '') {
            $urls = array_values(array_unique(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', $raw) ?: [],
            ))));
        }

        if (! is_array($urls) || count($urls) > 5) {
            $this->fail($key, __('Enter at most five HTTPS base URLs.'));
        }

        foreach ($urls as $url) {
            $parts = parse_url($url);

            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || ! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! is_string($parts['host'] ?? null)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])) {
                $this->fail($key, __('Every advertised route must be an HTTPS base URL without credentials, query, or fragment.'));
            }
        }
    }

    private function validatePrivateDisk(): void
    {
        $key = $this->formKey('data_share.disk');
        $disk = trim((string) ($this->values[$key] ?? ''));
        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config) || $disk === 'public' || ($config['visibility'] ?? null) === 'public') {
            $this->fail($key, __('Choose a configured private Laravel filesystem disk.'));
        }
    }

    private function validateDistinctPaths(): void
    {
        $settingKeys = [
            'data_share.outgoing_path_prefix',
            'data_share.receiving_path_prefix',
            'data_share.incoming_path_prefix',
            'data_share.path_prefix',
        ];
        $paths = array_map(
            fn (string $key): string => trim((string) ($this->values[$this->formKey($key)] ?? ''), '/'),
            $settingKeys,
        );

        foreach ($paths as $leftIndex => $left) {
            foreach ($paths as $rightIndex => $right) {
                if ($leftIndex !== $rightIndex
                    && ($left === $right || str_starts_with($left, $right.'/'))) {
                    $this->fail(
                        $this->formKey($settingKeys[$leftIndex]),
                        __('Outgoing, Receiving, Incoming, and Diagnostic paths must be distinct and non-overlapping.'),
                    );
                }
            }
        }
    }

    private function validateRelatedLimits(): void
    {
        $scalarKey = $this->formKey('data_share.transfer_limits.max_scalar_bytes');
        $lineKey = $this->formKey('data_share.transfer_limits.max_record_line_bytes');
        $packageKey = $this->formKey('data_share.transfer_limits.max_package_bytes');
        $scalar = (int) ($this->values[$scalarKey] ?? 0);
        $line = (int) ($this->values[$lineKey] ?? 0);
        $package = (int) ($this->values[$packageKey] ?? 0);

        if ($line < $scalar) {
            $this->fail($lineKey, __('The record-line limit must be at least the scalar limit.'));
        }

        if ($package < $line) {
            $this->fail($packageKey, __('The package limit must be at least the record-line limit.'));
        }

        $diagnosticScalarKey = $this->formKey('data_share.limits.max_scalar_bytes');
        $diagnosticPackageKey = $this->formKey('data_share.limits.max_package_bytes');

        if ((int) ($this->values[$diagnosticPackageKey] ?? 0) < (int) ($this->values[$diagnosticScalarKey] ?? 0)) {
            $this->fail($diagnosticPackageKey, __('The diagnostic package limit must be at least the diagnostic scalar limit.'));
        }
    }
}
