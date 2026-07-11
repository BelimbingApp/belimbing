<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\SettingsForm;
use App\Base\Settings\Support\SettingsFieldValue;
use Illuminate\Validation\ValidationException;

class Settings extends SettingsForm
{
    public function mount(SettingsService $settings): void
    {
        parent::mount($settings);
        $instance = app(DataShareInstanceIdentityResolver::class)->current();
        $defaults = [
            'data_share.instance.id' => $instance->id,
            'data_share.instance.name' => $instance->name,
            'data_share.instance.role' => $instance->role->value,
        ];

        foreach ($defaults as $key => $value) {
            if (! $settings->has($key)) {
                $this->values[$this->formKey($key)] = $value;
            }
        }
    }

    protected function group(): string
    {
        return 'data_share_identity';
    }

    /** @return list<string> */
    protected function groups(): array
    {
        return [
            'data_share_identity',
            'data_share_transport',
            'data_share_storage',
            'data_share_transfer_limits',
            'data_share_diagnostic_limits',
        ];
    }

    protected function pageTitle(): string
    {
        return __('Data Share Settings');
    }

    protected function pageSubtitle(): string
    {
        return __('Instance identity, HTTPS routes, private storage, retention, and hard transfer bounds stored in Base Settings.');
    }

    protected function pageHelp(): ?string
    {
        return __('Configure each server once here, then return to Data Share. The source publishes an expiring offer; the target pastes it, fetches the immutable package, and separately reviews and applies.');
    }

    protected function pageHelpLabel(): string
    {
        return __('About Data Share settings');
    }

    public function save(SettingsService $settings): void
    {
        $this->validateOfferUrls();
        $this->validatePrivateDisk();
        $this->validateDistinctPaths();
        $this->validateRelatedLimits();

        parent::save($settings);
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

    private function formKey(string $key): string
    {
        return SettingsFieldValue::formKey($key);
    }

    private function fail(string $formKey, string $message): never
    {
        throw ValidationException::withMessages(['values.'.$formKey => $message]);
    }
}
