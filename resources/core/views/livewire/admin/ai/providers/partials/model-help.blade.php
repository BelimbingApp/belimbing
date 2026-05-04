<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/**
 * Shared help content explaining the model table (Access column, costs).
 * Included in both the main Providers page and the ProviderSetup page-header help slot.
 */
?>
<div>
    <p class="font-medium text-ink">{{ __('Access column') }}</p>
    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
        <li>{{ __('The Access column groups three controls: the default star (★ / ☆), the offered-to-Agents checkbox, and the execution sliders button.') }}</li>
        <li>{{ __('★ marks the provider default — the fallback when an Agent does not pick a model. Click ☆ on another row to move the default.') }}</li>
        <li>{{ __('Missing Agent access: when the checkbox is off, the model is withheld from Agents — it no longer appears in model lists and cannot be selected, but the row stays for sync, catalog costs, and overrides.') }}</li>
        <li>{{ __('When the checkbox is on, the model is offered again in Agent pickers. If the default star sits on a model that is not offered, runtime falls back to another active model until you turn that model on or change the default.') }}</li>
        <li>{{ __('Open the sliders control for optional per-model execution overrides; it appears in the accent color when custom settings apply.') }}</li>
    </ul>
</div>

<div>
    <p class="font-medium text-ink">{{ __('Costs & billing') }}</p>
    <ul class="list-disc list-inside space-y-1 text-muted mt-1">
        <li>{{ __('API providers (OpenAI, Anthropic, etc.) bill per token used — costs are shown per 1M tokens.') }}</li>
        <li>{{ __('Subscription providers (GitHub Copilot) are included in your subscription at no extra per-token cost.') }}</li>
        <li>{{ __('Local providers (Ollama, vLLM) run on your own hardware and have no API fees.') }}</li>
        <li>{{ __('Click any cost cell to override the catalog default for that model.') }}</li>
    </ul>
</div>
