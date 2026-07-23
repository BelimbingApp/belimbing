<?php

namespace App\Base\System\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deploy-time guard for production-unsafe configuration.
 *
 * Run as part of a production deploy (or manually) to fail fast when the
 * running config would leak internals or weaken security: debug mode on,
 * a missing app key, insecure session cookies, or wildcard proxy trust.
 * Checks that only matter in production are enforced only when APP_ENV is
 * production; elsewhere they are reported as informational.
 */
#[AsCommand(name: 'blb:security:check')]
class SecurityCheckCommand extends Command
{
    protected $description = 'Fail when the running configuration is unsafe for production (debug, app key, session, proxies)';

    protected $signature = 'blb:security:check';

    public function handle(): int
    {
        $isProduction = $this->getLaravel()->isProduction();

        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $warnings */
        $warnings = [];

        if (empty(config('app.key'))) {
            $failures[] = 'APP_KEY is not set — sessions and encrypted values are insecure.';
        }

        $this->assess(
            $isProduction,
            (bool) config('app.debug') === true,
            'APP_DEBUG must be false in production — it leaks stack traces and config.',
            $failures,
            $warnings,
        );

        $this->assess(
            $isProduction,
            (bool) config('session.secure') !== true,
            'SESSION_SECURE_COOKIE should be true in production so the session cookie is HTTPS-only.',
            $failures,
            $warnings,
        );

        if ($this->trustsAllProxies()) {
            $message = "Trusted proxies is a wildcard ('*') — clients can spoof X-Forwarded-For. Pin TRUSTED_PROXIES to the real proxy.";
            $isProduction ? $failures[] = $message : $warnings[] = $message;
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        foreach ($failures as $failure) {
            $this->components->error($failure);
        }

        if ($failures !== []) {
            return self::FAILURE;
        }

        $this->components->info('Security configuration checks passed.');

        return self::SUCCESS;
    }

    /**
     * Record a production-only concern as a failure in production and a warning
     * elsewhere, so the same command is a hard gate on live config and a hint
     * during local development.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     */
    private function assess(bool $isProduction, bool $isViolated, string $message, array &$failures, array &$warnings): void
    {
        if (! $isViolated) {
            return;
        }

        if ($isProduction) {
            $failures[] = $message;

            return;
        }

        $warnings[] = $message;
    }

    private function trustsAllProxies(): bool
    {
        $configured = trim((string) config('security.trusted_proxies', ''));

        return $configured === '*';
    }
}
