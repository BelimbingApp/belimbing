<?php

namespace App\Base\Menu\Services;

use App\Base\Menu\MenuRegistry;

final class MenuRegistryLoader
{
    public function __construct(
        private readonly MenuRegistry $registry,
        private readonly MenuDiscoveryService $discovery,
    ) {}

    public function ensureLoaded(): void
    {
        if ($this->registry->getAll()->isNotEmpty()) {
            return;
        }

        $fingerprint = $this->discovery->configFingerprint();

        if ($this->registry->loadFromCache($fingerprint)) {
            return;
        }

        $this->refresh(persist: true, fingerprint: $fingerprint);
    }

    public function refresh(bool $persist, ?string $fingerprint = null): void
    {
        $this->registry->registerFromDiscovery($this->discovery->discover());

        $errors = $this->registry->validate();

        if (! empty($errors)) {
            logger()->error('Menu validation errors', ['errors' => $errors]);
        }

        if ($persist) {
            $this->registry->persist($fingerprint);
        }
    }
}
