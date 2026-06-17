<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\AI\DTO\AiProviderSummary;

/**
 * The image-processing family: AI providers that operate on pixels rather than
 * tokens (background removal, enhancement, upscaling). PhotoRoom is the first
 * member; Claid and others slot in here without touching the LLM family. Unlike
 * LLM providers, image credentials are company-scoped {@see AiProvider} rows
 * (family {@code image}).
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

        // PhotoRoom is the only image provider with a working cleanup client, so
        // it is the only one that can be "connected" (usable now). The others can
        // store credentials (configured) but their clients aren't built yet —
        // connected stays false. None is "coming soon": all four have a setup
        // screen.
        $photoRoom = $this->photoRoom->resolve($companyId);
        $photoRoomConfigured = $photoRoom['api_key'] !== null;

        $alibaba = $this->alibaba->resolve($companyId);
        $claid = $this->claid->resolve($companyId);
        $poof = $this->poof->resolve($companyId);
        $stability = $this->stability->resolve($companyId);
        $bedrock = $this->bedrock->resolve($companyId);

        $providers = [
            new AiProviderSummary(
                familyKey: self::KEY,
                providerKey: PhotoRoomConfiguration::PROVIDER,
                displayName: PhotoRoomConfiguration::PROVIDER_LABEL,
                connected: $photoRoomConfigured,
                configured: $photoRoomConfigured,
                description: (string) __('Fast, e-commerce-tuned background removal & product cutouts.'),
            ),
            $this->credentialOnlySummary(
                AlibabaConfiguration::PROVIDER,
                AlibabaConfiguration::PROVIDER_LABEL,
                $alibaba['api_key'] !== null,
                (string) __('Generative image editing via DashScope (Qwen/Wanxiang) — removal & replacement.'),
            ),
            $this->credentialOnlySummary(
                ClaidConfiguration::PROVIDER,
                ClaidConfiguration::PROVIDER_LABEL,
                $claid['api_key'] !== null,
                (string) __('AI image editing — background removal, upscaling & enhancement.'),
            ),
            $this->credentialOnlySummary(
                PoofConfiguration::PROVIDER,
                PoofConfiguration::PROVIDER_LABEL,
                $poof['api_key'] !== null,
                (string) __('Low-cost, high-resolution background removal.'),
            ),
            $this->credentialOnlySummary(
                StabilityConfiguration::PROVIDER,
                StabilityConfiguration::PROVIDER_LABEL,
                $stability['api_key'] !== null,
                (string) __('Stable Image edit API — background removal, search-and-recolor, erase & more.'),
            ),
            $this->credentialOnlySummary(
                BedrockConfiguration::PROVIDER,
                BedrockConfiguration::PROVIDER_LABEL,
                $bedrock['api_key'] !== null,
                (string) __('Stability image models (background removal, generation & edit) via Amazon Bedrock.'),
            ),
        ];

        // Vendor names in ascending (case-insensitive) order for the catalog.
        usort($providers, fn (AiProviderSummary $a, AiProviderSummary $b): int => strcasecmp($a->displayName, $b->displayName));

        return $providers;
    }

    /**
     * A provider whose credentials can be stored but whose cleanup client is
     * not built yet: configured when a key exists, never "connected" (usable).
     */
    private function credentialOnlySummary(string $providerKey, string $label, bool $keyStored, string $description): AiProviderSummary
    {
        return new AiProviderSummary(
            familyKey: self::KEY,
            providerKey: $providerKey,
            displayName: $label,
            connected: false,
            configured: $keyStored,
            description: $description,
        );
    }
}
