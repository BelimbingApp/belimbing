<?php

namespace App\Base\AI\Services;

use Illuminate\Support\Arr;
use Symfony\Component\Process\ExecutableFinder;

class ExecutableLocator
{
    /**
     * @param  string|list<string>  $candidates
     */
    public function find(string|array $candidates): ?string
    {
        $finder = new ExecutableFinder;

        foreach (Arr::wrap($candidates) as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if ((str_contains($candidate, '\\') || str_contains($candidate, '/')) && is_executable($candidate)) {
                return $candidate;
            }

            $resolved = $finder->find($candidate);
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }
}
