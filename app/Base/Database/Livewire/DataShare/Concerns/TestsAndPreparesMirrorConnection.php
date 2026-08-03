<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\SupabaseMirrorSetupService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\Str as BlbStr;
use Illuminate\Validation\ValidationException;
use Throwable;

trait TestsAndPreparesMirrorConnection
{
    public function testMirrorConnection(DataShareMirrorManager $mirror, SettingsService $settings): void
    {
        $this->requireCapability('admin.system.data-share-settings.manage');
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();
        $passwordWasEntered = $provider === 'supabase' && $this->supabaseManualDatabasePassword !== '';

        try {
            $value = $this->applySupabaseManualPassword($value, $provider, $settings, clearPassword: false);
            $value = $this->mirrorUrlForConnectionTest($value, $settings, $key);

            $this->validateMirrorUrl($key, $value);
            $status = $mirror->testConnection($value, $provider);
            $result = $status->toArray();

            if (! ($result['reachable'] ?? false)) {
                $errorKey = $passwordWasEntered ? 'supabaseManualDatabasePassword' : $key;
                $this->failProperty($errorKey, (string) ($result['message'] ?? __('The mirror connection is unavailable.')));
            }

            $settings->set('data_share.mirror.provider', $provider);
            $settings->set('data_share.mirror.url', $value);

            $this->recordSupabaseInitializationNeed($provider, $result, $settings);

            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;
            $this->originalMirrorProvider = $provider;
            $this->replaceSavedSupabaseConnection = false;
            $this->resetValidation('values.'.$key);
            $this->resetValidation('supabaseManualDatabasePassword');

            $this->finishSuccessfulConnectionTest($result, $passwordWasEntered);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $exception) {
            $errorKey = $passwordWasEntered ? 'supabaseManualDatabasePassword' : $key;
            $this->failProperty($errorKey, DataShareMirrorException::unexpected('connection', $exception)->getMessage());
        }
    }

    private function prepareAndTestMirrorUrl(SettingsService $settings, DataShareMirrorManager $mirror): void
    {
        $key = $this->formKey('data_share.mirror.url');
        $value = trim((string) ($this->values[$key] ?? ''));
        $provider = $this->selectedMirrorProvider();
        $value = $this->applySupabaseManualPassword($value, $provider, $settings);
        $this->values[$key] = $value;

        if (($value === '' || $this->isSavedMirrorMask($value)) && $settings->has('data_share.mirror.url')) {
            $this->values[$key] = BlbStr::DEFAULT_SAVED_SECRET_MASK;

            if ($provider !== $this->originalMirrorProvider) {
                $this->testSavedUrlWithChangedProvider($settings, $mirror, $provider, $key);
            }

            return;
        }

        if ($value === '' || $this->isSavedMirrorMask($value)) {
            return;
        }

        $this->validateMirrorUrl($key, $value);
        $this->requireReachableMirror($mirror, $value, $provider, $key);
    }

    private function mirrorUrlForConnectionTest(string $value, SettingsService $settings, string $key): string
    {
        if (! $this->isSavedMirrorMask($value) && $value !== '') {
            return $value;
        }

        if ($value === '' && ! $settings->has('data_share.mirror.url')) {
            $this->fail($key, __('Enter a PostgreSQL URL before testing the mirror connection.'));
        }

        $savedUrl = $settings->get('data_share.mirror.url');
        if (! is_string($savedUrl) || trim($savedUrl) === '') {
            $message = $this->isSavedMirrorMask($value)
                ? __('Enter a PostgreSQL URL before testing the mirror connection.')
                : __('The saved mirror credential could not be read. Replace it and try again.');
            $this->fail($key, $message);
        }

        return $savedUrl;
    }

    /** @param array<string, mixed> $result */
    private function recordSupabaseInitializationNeed(string $provider, array $result, SettingsService $settings): void
    {
        if ($provider !== 'supabase') {
            return;
        }

        if (($result['initializable'] ?? false) && ! ($result['available'] ?? false)) {
            $settings->set(SupabaseMirrorSetupService::NEEDS_INITIALIZATION_SETTING, true);

            return;
        }

        $settings->forget(SupabaseMirrorSetupService::NEEDS_INITIALIZATION_SETTING);
    }

    /** @param array<string, mixed> $result */
    private function finishSuccessfulConnectionTest(array $result, bool $passwordWasEntered): void
    {
        if ($passwordWasEntered) {
            $this->supabaseManualDatabasePassword = '';
            $this->dispatch('clear-secret-input', id: 'supabase-manual-database-password');
        }

        $message = match (true) {
            (bool) ($result['available'] ?? false) => __('Connection successful and saved.'),
            (bool) ($result['initializable'] ?? false) => __('Connection successful and saved. Initialize the mirror before transferring data.'),
            default => (string) ($result['message'] ?? __('Connection successful and saved, but the database is not ready to use.')),
        };

        $this->notify($message, ($result['available'] ?? false) ? 'success' : 'warning');
    }

    private function testSavedUrlWithChangedProvider(SettingsService $settings, DataShareMirrorManager $mirror, string $provider, string $key): void
    {
        $savedUrl = $settings->get('data_share.mirror.url');
        if (! is_string($savedUrl)) {
            $this->fail($key, __('The saved mirror credential could not be read. Replace it before changing provider.'));
        }

        $this->requireReachableMirror($mirror, $savedUrl, $provider, $key);
    }

    private function requireReachableMirror(DataShareMirrorManager $mirror, string $url, string $provider, string $key): void
    {
        try {
            $status = $mirror->testConnection($url, $provider)->toArray();
        } catch (Throwable $exception) {
            $this->fail($key, DataShareMirrorException::unexpected('connection', $exception)->getMessage());
        }

        if (! ($status['reachable'] ?? false)) {
            $this->fail($key, (string) ($status['message'] ?? __('The mirror connection is unavailable.')));
        }
    }

    private function applySupabaseManualPassword(
        string $url,
        string $provider,
        SettingsService $settings,
        bool $clearPassword = true,
    ): string {
        $password = $this->supabaseManualDatabasePassword;

        if ($clearPassword) {
            $this->supabaseManualDatabasePassword = '';
            $this->dispatch('clear-secret-input', id: 'supabase-manual-database-password');
        }

        if ($provider !== 'supabase' || $password === '') {
            return $url;
        }

        if ($url === '' || $this->isSavedMirrorMask($url)) {
            $savedUrl = $settings->get('data_share.mirror.url');

            if (is_string($savedUrl)) {
                $url = $savedUrl;
            }
        }

        $updatedUrl = preg_replace_callback(
            '/\A(postgres(?:ql)?:\/\/)([^\/?#@]+)@/i',
            static function (array $matches) use ($password): string {
                $username = explode(':', $matches[2], 2)[0];

                return $matches[1].$username.':'.rawurlencode($password).'@';
            },
            $url,
            1,
        );

        return is_string($updatedUrl) ? $updatedUrl : $url;
    }

    private function isSavedMirrorMask(string $value): bool
    {
        return BlbStr::isUnchangedSecretValue($value, BlbStr::DEFAULT_SAVED_SECRET_MASK);
    }

    private function failProperty(string $property, string $message): never
    {
        throw ValidationException::withMessages([$property => $message]);
    }
}
