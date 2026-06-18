<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\DomainLifecycleLedger;

final class NullDomainLifecycleLedger implements DomainLifecycleLedger
{
    /**
     * @param  list<string>  $domains
     * @return array<string, array{occurred_at: string, actor_type: string, actor_id: int, actor_name: string|null, actor_email: string|null, status: string|null}>
     */
    public function latestInstallations(array $domains): array
    {
        return [];
    }
}
