<?php

namespace App\Base\Foundation\Contracts;

interface DomainLifecycleLedger
{
    /**
     * Latest retained install action for each requested domain.
     *
     * @param  list<string>  $domains
     * @return array<string, array{occurred_at: string, actor_type: string, actor_id: int, actor_name: string|null, actor_email: string|null, status: string|null}>
     */
    public function latestInstallations(array $domains): array;
}
