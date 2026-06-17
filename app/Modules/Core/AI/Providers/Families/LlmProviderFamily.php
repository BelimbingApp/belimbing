<?php

namespace App\Modules\Core\AI\Providers\Families;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\AI\DTO\AiProviderSummary;
use App\Modules\Core\AI\Models\AiProvider;

/**
 * The language-model family: a read-only spine view over the existing
 * company-scoped {@see AiProvider} rows. The family owns nothing new — model
 * discovery, task-model routing, sampling/reasoning controls, and pricing
 * stay exactly where they are. This adapter only maps connected providers to
 * the neutral summary the hub overview needs.
 */
class LlmProviderFamily implements AiProviderFamily
{
    public const KEY = 'llm';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) __('Language models');
    }

    public function capabilityLabel(): string
    {
        return (string) __('Chat & reasoning');
    }

    public function providers(?int $companyId): array
    {
        if ($companyId === null) {
            return [];
        }

        return AiProvider::query()
            ->forCompany($companyId)
            ->llm()
            ->active()
            ->withCount('models')
            ->orderBy('priority')
            ->orderBy('display_name')
            ->get()
            ->map(fn (AiProvider $provider): AiProviderSummary => new AiProviderSummary(
                familyKey: self::KEY,
                providerKey: (string) $provider->name,
                displayName: (string) ($provider->display_name ?: $provider->name),
                connected: true,
                configured: true,
            ))
            ->all();
    }
}
