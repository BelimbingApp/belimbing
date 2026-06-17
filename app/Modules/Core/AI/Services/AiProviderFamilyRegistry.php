<?php

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Contracts\AiProviderFamily;

/**
 * Collects the AI provider families registered through the container tag and
 * hands them to the providers hub. Mirrors the AI tool registry: families
 * self-declare, so this registry never knows family-specific details.
 */
class AiProviderFamilyRegistry
{
    /** @var list<AiProviderFamily> */
    private array $families;

    /**
     * @param  iterable<AiProviderFamily>  $families
     */
    public function __construct(iterable $families = [])
    {
        $this->families = array_values(is_array($families) ? $families : iterator_to_array($families));
    }

    /**
     * @return list<AiProviderFamily>
     */
    public function families(): array
    {
        return $this->families;
    }

    public function family(string $key): ?AiProviderFamily
    {
        foreach ($this->families as $family) {
            if ($family->key() === $key) {
                return $family;
            }
        }

        return null;
    }
}
