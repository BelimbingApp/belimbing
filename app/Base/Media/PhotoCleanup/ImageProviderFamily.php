<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\AI\DTO\AiProviderSummary;

/**
 * The image-processing family: AI providers that operate on pixels rather than
 * tokens (background removal, enhancement, upscaling). PhotoRoom and Poof are
 * the first members with working cleanup clients; Claid, Stability, DashScope,
 * and Bedrock can store credentials (configured) but their cleanup clients are
 * not built yet — they stay `Key stored`, never `Ready`, until an adapter
 * registers in {@see PhotoCleanupProviderRegistry}. Unlike LLM providers, image
 * credentials are company-scoped {@see AiProvider} rows (family {@code image}).
 *
 * `connected` (the `Ready` badge) follows the registry: a row is `Ready` once
 * its adapter is bound AND a key is stored — exactly the existing taxonomy. The
 * operator's active choice ({@see PhotoCleanupSelection}) is marked `active` so
 * the surface can show which `Ready` provider photo cleanup will run through.
 *
 * See docs/plans/media-photo-cleanup-providers.md for the family's internal
 * provider registry.
 */
class ImageProviderFamily implements AiProviderFamily
{
    public const KEY = 'image';

    public function __construct(
        private readonly PhotoRoomConfiguration $photoRoom,
        private readonly AlibabaConfiguration $alibaba,
        private readonly ClaidConfiguration $claid,
        private readonly PoofConfiguration $poof,
        private readonly StabilityConfiguration $stability,
        private readonly BedrockConfiguration $bedrock,
        private readonly PhotoCleanupProviderRegistry $registry,
        private readonly PhotoCleanupSelection $selection,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('Vision');
    }

    public function capabilityLabel(): string
    {
        return (string) __('Image generation & editing');
    }

    public function providers(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        $activeKey = $this->selection->activeProviderKey($companyId);

        $providers = [
            $this->summary(
                PhotoRoomConfiguration::PROVIDER,
                PhotoRoomConfiguration::PROVIDER_LABEL,
                $this->photoRoom->resolve($companyId)['api_key'] !== null,
                (string) __('Fast, e-commerce-tuned background removal & product cutouts.'),
                $activeKey,
            ),
            $this->summary(
                AlibabaConfiguration::PROVIDER,
                AlibabaConfiguration::PROVIDER_LABEL,
                $this->alibaba->resolve($companyId)['api_key'] !== null,
                (string) __('Generative image editing via DashScope (Qwen/Wanxiang) — removal & replacement.'),
                $activeKey,
            ),
            $this->summary(
                ClaidConfiguration::PROVIDER,
                ClaidConfiguration::PROVIDER_LABEL,
                $this->claid->resolve($companyId)['api_key'] !== null,
                (string) __('AI image editing — background removal, upscaling & enhancement.'),
                $activeKey,
            ),
            $this->summary(
                PoofConfiguration::PROVIDER,
                PoofConfiguration::PROVIDER_LABEL,
                $this->poof->resolve($companyId)['api_key'] !== null,
                (string) __('Low-cost, high-resolution background removal.'),
                $activeKey,
            ),
            $this->summary(
                StabilityConfiguration::PROVIDER,
                StabilityConfiguration::PROVIDER_LABEL,
                $this->stability->resolve($companyId)['api_key'] !== null,
                (string) __('Stable Image edit API — background removal, search-and-recolor, erase & more.'),
                $activeKey,
            ),
            $this->summary(
                BedrockConfiguration::PROVIDER,
                BedrockConfiguration::PROVIDER_LABEL,
                $this->bedrock->resolve($companyId)['api_key'] !== null,
                (string) __('Stability image models (background removal, generation & edit) via Amazon Bedrock.'),
                $activeKey,
            ),
        ];

        // Vendor names in ascending (case-insensitive) order for the catalog.
        usort($providers, fn (AiProviderSummary $a, AiProviderSummary $b): int => strcasecmp($a->displayName, $b->displayName));

        return $providers;
    }

    /**
     * One provider summary. `connected` (the `Ready` badge) is honest about the
     * registry: a row is `Ready` only when a cleanup adapter is registered for
     * its key AND a key is stored. `active` marks the operator's chosen
     * provider among the `Ready` ones.
     */
    private function summary(string $providerKey, string $label, bool $keyStored, string $description, string $activeKey): AiProviderSummary
    {
        $connected = $keyStored && $this->registry->supports($providerKey);

        return new AiProviderSummary(
            familyKey: self::KEY,
            providerKey: $providerKey,
            displayName: $label,
            connected: $connected,
            configured: $keyStored,
            description: $description,
            active: $connected && $activeKey === $providerKey,
        );
    }
}
