<?php

namespace App\Base\Settings\Services;

use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use Illuminate\Support\Str;

/**
 * Registry for persisted operational state that is not a runtime parameter.
 *
 * Claims establish namespace ownership only. They do not provide defaults,
 * validation, scope policy, encryption policy, or editable UI metadata.
 */
final class RuntimeSettingClaimRegistry
{
    /**
     * @var list<string>|null
     */
    private ?array $claims = null;

    public function claims(string $key): bool
    {
        foreach ($this->all() as $claim) {
            if (Str::is($claim, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->claims !== null) {
            return $this->claims;
        }

        $claims = [];

        foreach ((array) config('settings.runtime', []) as $claim) {
            if (! is_string($claim) || trim($claim) === '') {
                throw new InvalidSettingDefinitionException(
                    'Runtime setting claims must be non-empty strings.',
                );
            }

            $claims[] = $claim;
        }

        sort($claims, SORT_STRING);

        return $this->claims = array_values(array_unique($claims));
    }

    public function refresh(): void
    {
        $this->claims = null;
    }
}
