<?php

namespace App\Base\Foundation\ModuleManifest;

final class ModuleVersionConstraint
{
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if ($version !== '') {
            foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $group) {
                if ($group !== '' && $this->groupSatisfies($version, $group)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function groupSatisfies(string $version, string $constraint): bool
    {
        $constraint = preg_replace('/(>=|<=|>|<|=|==|\^|~)\s+/', '$1', trim($constraint)) ?? $constraint;
        $tokens = array_values(array_filter(preg_split('/[\s,]+/', $constraint) ?: []));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! $this->tokenSatisfies($version, $token)) {
                return false;
            }
        }

        return true;
    }

    private function tokenSatisfies(string $version, string $token): bool
    {
        [$operator, $constraint] = $this->parseToken($token);

        if ($constraint === '*') {
            return true;
        }

        if ($constraint === '' || preg_match('/^v?\d+(?:\.\d+){0,2}(?:\.\*)?$/', $constraint) !== 1) {
            return false;
        }

        return match ($operator) {
            '^' => $this->versionAtLeast($version, $constraint) && $this->versionBelow($version, $this->caretUpperBound($constraint)),
            '~' => $this->versionAtLeast($version, $constraint) && $this->versionBelow($version, $this->tildeUpperBound($constraint)),
            default => $this->compareToken($version, $operator, $constraint),
        };
    }

    /** @return array{0: string, 1: string} */
    private function parseToken(string $token): array
    {
        foreach (['>=', '<=', '==', '>', '<', '=', '^', '~'] as $operator) {
            if (str_starts_with($token, $operator)) {
                return [$operator, substr($token, strlen($operator))];
            }
        }

        return ['', $token];
    }

    private function compareToken(string $version, string $operator, string $constraint): bool
    {
        if (str_ends_with($constraint, '.*')) {
            return $this->versionAtLeast($version, $this->wildcardLowerBound($constraint))
                && $this->versionBelow($version, $this->wildcardUpperBound($constraint));
        }

        return version_compare($this->normalizeVersion($version), $this->normalizeVersion($constraint), $this->normalizedOperator($operator));
    }

    private function versionAtLeast(string $version, string $constraint): bool
    {
        return version_compare($this->normalizeVersion($version), $this->normalizeVersion($constraint), '>=');
    }

    private function versionBelow(string $version, string $constraint): bool
    {
        return version_compare($this->normalizeVersion($version), $constraint, '<');
    }

    private function normalizedOperator(string $operator): string
    {
        return match ($operator) {
            '', '==' => '=',
            default => $operator,
        };
    }

    private function caretUpperBound(string $version): string
    {
        $parts = array_map('intval', explode('.', $this->normalizeVersion($version)));
        $major = $parts[0] ?? 0;
        $minor = $parts[1] ?? 0;
        $patch = $parts[2] ?? 0;

        if ($major > 0) {
            return ($major + 1).'.0.0';
        }

        return $minor > 0 ? '0.'.($minor + 1).'.0' : '0.0.'.($patch + 1);
    }

    private function tildeUpperBound(string $version): string
    {
        $normalized = $this->normalizeVersion($version);
        $parts = array_map('intval', explode('.', $normalized));

        if (substr_count($normalized, '.') >= 2) {
            return ($parts[0] ?? 0).'.'.(($parts[1] ?? 0) + 1).'.0';
        }

        return (($parts[0] ?? 0) + 1).'.0.0';
    }

    private function wildcardLowerBound(string $version): string
    {
        return str_replace('*', '0', $this->normalizeVersion($version));
    }

    private function wildcardUpperBound(string $version): string
    {
        $parts = explode('.', $this->normalizeVersion($version));
        $wildcardIndex = array_search('*', $parts, true);

        if ($wildcardIndex === false) {
            return $this->normalizeVersion($version);
        }

        $base = array_slice($parts, 0, $wildcardIndex);
        $incrementIndex = max(0, $wildcardIndex - 1);
        $base[$incrementIndex] = (string) (((int) ($base[$incrementIndex] ?? 0)) + 1);

        while (count($base) < 3) {
            $base[] = '0';
        }

        return implode('.', array_slice($base, 0, 3));
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim(trim($version), 'vV');
    }
}
