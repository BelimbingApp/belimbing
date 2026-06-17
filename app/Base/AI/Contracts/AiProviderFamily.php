<?php

namespace App\Base\AI\Contracts;

use App\Base\AI\DTO\AiProviderSummary;

/**
 * A family of AI providers that share a capability shape — language models,
 * image processing, and (later) world/video/speech. Each family is a deep,
 * self-describing module: it owns its capability model, runtime, credential
 * storage, selection, and billing, and exposes only the thin spine the
 * providers hub needs.
 *
 * Families register through {@see self::CONTAINER_TAG} exactly like AI
 * {@see Tool}s, so Core/AI never imports another family's domain classes and
 * any module can contribute a family. The contract lives in Base/AI so both
 * Core/AI (LLM) and other modules (e.g. Base/Media's image family) implement
 * it without a wrong-way dependency.
 */
interface AiProviderFamily
{
    /**
     * Container tag under which modules register provider families.
     */
    public const CONTAINER_TAG = 'blb.ai.provider_families';

    /**
     * Stable machine key for the family (e.g. 'llm', 'image').
     */
    public function key(): string;

    /**
     * Human label for the family section (e.g. 'Language models').
     */
    public function label(): string;

    /**
     * Short description of what this family does (e.g. 'Chat & reasoning',
     * 'Background removal & enhancement').
     */
    public function capabilityLabel(): string;

    /**
     * The providers in this family, as family-neutral summaries. The company
     * scopes families whose credentials are per-company (LLM); families with
     * global credentials (image) ignore it.
     *
     * @return list<AiProviderSummary>
     */
    public function providers(?int $companyId): array;
}
