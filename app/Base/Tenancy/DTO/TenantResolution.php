<?php

namespace App\Base\Tenancy\DTO;

/** The tenant selected for a request and the resolver that selected it. */
final readonly class TenantResolution
{
    public const string HEADER = 'header';

    public const string HOST = 'host';

    public const string SESSION = 'session';

    public function __construct(
        public string $resolver,
        public int $tenantId,
    ) {}
}
