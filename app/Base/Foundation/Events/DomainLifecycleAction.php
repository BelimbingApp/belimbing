<?php

namespace App\Base\Foundation\Events;

final readonly class DomainLifecycleAction
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $domain,
        public string $action,
        public string $status,
        public array $details = [],
    ) {}
}
