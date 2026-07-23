<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;

/**
 * Resolves the active photo-cleanup {@see PhotoCleanupProvider} for a company
 * from a company-scoped setting (`media.photo_cleanup.provider`) instead of a
 * fixed container binding. The setting defaults to `photoroom` so existing
 * deployments keep their behaviour until an operator picks another provider.
 *
 * Selection validates the *choice* only: an unknown/unregistered key fails with
 * a clear operator-facing {@see PhotoCleanupException}. Credential validation
 * stays with the adapter (its `requireConfigured` throws when no key is stored),
 * so this layer never imports provider configuration. The engine stays sealed.
 * See docs/plans/media-photo-cleanup-providers.md.
 */
class PhotoCleanupSelection
{
    public const SETTING_KEY = 'media.photo_cleanup.provider';

    public const DEFAULT_PROVIDER = PhotoRoomConfiguration::PROVIDER;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PhotoCleanupProviderRegistry $registry,
    ) {}

    /**
     * The provider key the operator has chosen for this company (default
     * `photoroom` when unset). Exposed for the operator surface so it can mark
     * the active row without re-resolving the adapter.
     */
    public function activeProviderKey(?int $companyId): string
    {
        $key = $companyId !== null
            ? $this->settings->get(self::SETTING_KEY, Scope::company($companyId))
            : self::DEFAULT_PROVIDER;

        return is_string($key) && $key !== '' ? $key : self::DEFAULT_PROVIDER;
    }

    /**
     * Resolve the active adapter, failing honestly on an unknown choice. A
     * registered adapter whose key has no stored credentials is not an error
     * here — the adapter's `requireConfigured` raises it at call time.
     */
    public function resolveProvider(?int $companyId): PhotoCleanupProvider
    {
        $providerKey = $this->activeProviderKey($companyId);

        $adapter = $this->registry->adapter($providerKey);

        if ($adapter === null) {
            throw PhotoCleanupException::unknownProvider($providerKey);
        }

        return $adapter;
    }

    public function setActiveProvider(int $companyId, string $providerKey): void
    {
        $this->settings->set(self::SETTING_KEY, $providerKey, Scope::company($companyId));
    }
}
