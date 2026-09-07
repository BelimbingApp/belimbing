<?php

namespace Tests\Support\DomainCommands;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;

/**
 * Fixture command: proves TenantScopedCommand asserts before handle().
 */
final class ZzTenantScopedProbeCommand extends TenantScopedCommand
{
    public static bool $handled = false;

    public static ?int $tenantSeenInHandle = null;

    public static ?int $requestContextTenantInHandle = null;

    protected $signature = 'zz:tenant-scoped-probe';

    protected $description = 'Fixture: tenant-scoped probe';

    public function handle(TenantContext $tenants): int
    {
        self::$handled = true;
        self::$tenantSeenInHandle = $tenants->currentTenantId();
        self::$requestContextTenantInHandle = app(RequestContext::class)->tenantId;

        return self::SUCCESS;
    }

    public static function reset(): void
    {
        self::$handled = false;
        self::$tenantSeenInHandle = null;
        self::$requestContextTenantInHandle = null;
    }
}
