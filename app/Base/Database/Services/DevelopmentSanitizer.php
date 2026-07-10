<?php

namespace App\Base\Database\Services;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Database\DTO\DevelopmentSanitizationResult;
use App\Base\Database\Exceptions\DevelopmentSanitizationException;

class DevelopmentSanitizer
{
    /**
     * @var list<DevelopmentSanitizationContributor>
     */
    private array $contributors;

    /**
     * @param  iterable<DevelopmentSanitizationContributor>  $contributors
     */
    public function __construct(
        private readonly DevelopmentInstanceGuard $environment,
        iterable $contributors,
    ) {
        $this->contributors = [...$contributors];
        $this->guardUniqueKeys();
    }

    /** @return list<DevelopmentSanitizationResult> */
    public function preview(): array
    {
        $this->environment->assertDevelopment(__('Database development sanitization'));

        return array_map(
            fn (DevelopmentSanitizationContributor $contributor): DevelopmentSanitizationResult => $contributor->preview(),
            $this->contributors,
        );
    }

    /** @return list<DevelopmentSanitizationResult> */
    public function apply(): array
    {
        $this->environment->assertDevelopment(__('Database development sanitization'));

        return array_map(
            fn (DevelopmentSanitizationContributor $contributor): DevelopmentSanitizationResult => $contributor->apply(),
            $this->contributors,
        );
    }

    private function guardUniqueKeys(): void
    {
        $keys = [];

        foreach ($this->contributors as $contributor) {
            $key = $contributor->key();

            if (isset($keys[$key])) {
                throw DevelopmentSanitizationException::duplicateContributor($key);
            }

            $keys[$key] = true;
        }
    }
}
