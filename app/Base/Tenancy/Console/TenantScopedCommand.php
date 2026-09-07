<?php

namespace App\Base\Tenancy\Console;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantInactiveException;
use App\Base\Tenancy\Exceptions\TenantOptionRequiredException;
use App\Base\Tenancy\Exceptions\TenantUnknownException;
use App\Base\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Domain (and other) console commands that must run inside one tenant.
 *
 * Resolves `--tenant=<id>`, refuses unknown or inactive tenants, binds
 * {@see TenantContext} before `handle()`, and refreshes audit
 * {@see RequestContext} so command audit rows carry the tenant.
 */
abstract class TenantScopedCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        if (! $this->getDefinition()->hasOption('tenant')) {
            $this->addOption(
                'tenant',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant ID to bind for this command',
            );
        }
    }

    /**
     * Assert and bind the tenant before Laravel invokes `handle()`.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $tenantId = $this->resolveTenantOption($input);
            $this->bindTenant($tenantId);
        } catch (TenantOptionRequiredException|TenantUnknownException|TenantInactiveException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Outside unit tests, CommandFinished is bridged from Symfony TERMINATE
        // after CommandListener stamps audit rows. Inside Pest, that bridge is
        // disabled, so clear in finally instead.
        if (! $this->laravel->runningUnitTests()) {
            $app = $this->laravel;
            $app['events']->listen(CommandFinished::class, static function () use ($app): void {
                self::clearTenantBinding($app);
            });
        }

        try {
            return parent::execute($input, $output);
        } finally {
            if ($this->laravel->runningUnitTests()) {
                self::clearTenantBinding($this->laravel);
            }
        }
    }

    private function resolveTenantOption(InputInterface $input): int
    {
        $raw = $input->getOption('tenant');

        // A whole id only: is_numeric() admits "12.9", "1e1" and " 12", and the
        // cast would then bind a tenant the operator did not name.
        if (! is_scalar($raw) || preg_match('/^\d+$/', (string) $raw) !== 1) {
            throw new TenantOptionRequiredException;
        }

        return (int) $raw;
    }

    private function bindTenant(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantUnknownException($tenantId);
        }

        $status = (string) $tenant->status;
        if ($status !== 'active') {
            throw new TenantInactiveException($tenantId, $status);
        }

        app(TenantContext::class)->set($tenantId);

        // RequestContext is a singleton stamped at first resolve. Forget it so
        // CommandFinished audit rows read the tenant we just bound.
        if ($this->laravel->bound(RequestContext::class)) {
            $this->laravel->forgetInstance(RequestContext::class);
        }
    }

    private static function clearTenantBinding(Application $app): void
    {
        $app->make(TenantContext::class)->clear();

        if ($app->bound(RequestContext::class)) {
            $app->forgetInstance(RequestContext::class);
        }
    }
}
