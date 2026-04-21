<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Contracts;

use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Enums\ProviderOperation;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ProviderField;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;
use Illuminate\Validation\ValidationException;

/**
 * Owns a provider's configuration shape, validation, editor schema, and runtime mapping.
 *
 * Callers resolve one definition from the registry and interact with it
 * instead of branching on provider names or reading raw config arrays.
 */
interface ProviderDefinition
{
    /**
     * Stable provider key matching the catalog overlay key and `ai_providers.name`.
     */
    public function key(): string;

    /**
     * Auth mechanism this provider uses.
     */
    public function authType(): AuthType;

    /**
     * Default base URL for this provider (from catalog or hard-coded).
     */
    public function defaultBaseUrl(): string;

    /**
     * Fields to render in the setup/edit form for the given operation.
     *
     * @return list<ProviderField>
     */
    public function editorFields(ProviderOperation $operation): array;

    /**
     * Validate and normalize raw form input for the given operation.
     *
     * Returns an array with keys that map directly to `AiProvider` model
     * attributes: `base_url`, `credentials`, `connection_config`, `auth_type`.
     *
     * For edit operations, secret fields left blank should be omitted from
     * the `credentials` array (meaning "keep existing value").
     *
     * @param  array<string, mixed>  $input  Raw form input keyed by field key
     * @param  ProviderOperation  $operation  Whether creating or editing
     * @return array<string, mixed> Normalized model attributes
     *
     * @throws ValidationException
     */
    public function validateAndNormalize(array $input, ProviderOperation $operation): array;

    /**
     * Resolve a persisted provider record into runtime configuration.
     *
     * Handles provider-specific credential transformations (e.g. token
     * exchange for GitHub Copilot, connectivity probes for local providers).
     */
    public function resolveRuntime(AiProvider $provider): ResolvedProviderConfig;

    /**
     * Return provider-owned model discovery results when generic /models is unsafe or inapplicable.
     *
     * Returning `null` delegates discovery to the shared OpenAI-compatible
     * `/models` path in Base AI. Returning a list makes the definition the
     * source of truth for discovery policy.
     *
     * @return list<array{model_id: string, display_name: string}>|null
     */
    public function discoverModels(AiProvider $provider): ?array;
}
