<?php

namespace App\Base\System\Fixtures;

use RuntimeException;

final class SuggestedGuardSubject
{
    public function find(int $tenantId): bool
    {
        if ($tenantId < 1) {
            throw new RuntimeException('A tenant is required.');
        }

        return $this->records()->where('tenant_id', $tenantId)->exists();
    }
}
