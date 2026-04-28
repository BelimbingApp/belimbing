<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Concerns;

use App\Modules\Core\Company\Models\ExternalAccess;
use Illuminate\Support\Collection;

trait SortsExternalAccesses
{
    /**
     * @param  Collection<int, ExternalAccess>  $accesses
     * @return Collection<int, ExternalAccess>
     */
    protected function sortExternalAccessesByColumn(
        Collection $accesses,
        string $sortBy,
        string $sortDir,
        string $principalColumn,
    ): Collection {
        $dir = $sortDir === 'desc' ? -1 : 1;

        return $accesses
            ->sort(fn (ExternalAccess $a, ExternalAccess $b): int => $this->compareExternalAccesses(
                $a,
                $b,
                $sortBy,
                $dir,
                $principalColumn,
            ))
            ->values();
    }

    private function compareExternalAccesses(
        ExternalAccess $a,
        ExternalAccess $b,
        string $sortBy,
        int $dir,
        string $principalColumn,
    ): int {
        $primary = $dir * match ($sortBy) {
            $principalColumn => strcmp(
                $this->externalAccessPrincipalName($a, $principalColumn),
                $this->externalAccessPrincipalName($b, $principalColumn),
            ),
            'permissions' => strcmp($this->externalAccessPermissionsKey($a), $this->externalAccessPermissionsKey($b)),
            'access_status' => $this->externalAccessStatusRank($a) <=> $this->externalAccessStatusRank($b),
            'granted_at' => strcmp($this->externalAccessTimestamp($a->access_granted_at), $this->externalAccessTimestamp($b->access_granted_at)),
            'expires_at' => strcmp($this->externalAccessTimestamp($a->access_expires_at), $this->externalAccessTimestamp($b->access_expires_at)),
            default => strcmp(
                $this->externalAccessPrincipalName($a, $principalColumn),
                $this->externalAccessPrincipalName($b, $principalColumn),
            ),
        };

        if ($primary !== 0) {
            return $primary;
        }

        return $a->id <=> $b->id;
    }

    private function externalAccessPrincipalName(ExternalAccess $access, string $principalColumn): string
    {
        return match ($principalColumn) {
            'company' => (string) ($access->company?->name ?? ''),
            default => (string) ($access->user?->name ?? ''),
        };
    }

    private function externalAccessPermissionsKey(ExternalAccess $access): string
    {
        $permissions = $access->permissions;
        $permissions = is_array($permissions) ? $permissions : [];
        sort($permissions);

        return implode(',', $permissions);
    }

    private function externalAccessStatusRank(ExternalAccess $access): int
    {
        if ($access->isValid()) {
            return 0;
        }

        if ($access->isPending()) {
            return 1;
        }

        if ($access->hasExpired()) {
            return 2;
        }

        return 3;
    }

    private function externalAccessTimestamp(?\DateTimeInterface $value): string
    {
        return $value === null ? '' : (string) $value->getTimestamp();
    }
}
