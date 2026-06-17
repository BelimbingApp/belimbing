<?php

namespace App\Base\Update\Services;

use App\Base\Settings\Contracts\SettingsService;

/**
 * Stores the durable run records shown on the Deployment update page.
 */
class DeploymentRunHistory
{
    private const LAST_RELOAD_KEY = 'system.update.frankenphp.last_reload';

    private const COMPOSER_RUN_KEY = 'system.update.composer.last_run';

    private const FRONTEND_RUN_KEY = 'system.update.frontend.last_run';

    private const DEPLOYMENT_RUN_KEY = 'system.update.deployment.last_run';

    public function __construct(private readonly SettingsService $settings) {}

    public function rememberComposerRun(bool $ok, string $message): void
    {
        $this->rememberRun(self::COMPOSER_RUN_KEY, $ok, $message);
    }

    public function rememberFrontendRun(bool $ok, string $message, string $packageManager): void
    {
        $this->rememberRun(self::FRONTEND_RUN_KEY, $ok, $message, ['pm' => $packageManager]);
    }

    public function rememberReload(bool $ok, string $message, string $adminUrl): void
    {
        $this->rememberRun(self::LAST_RELOAD_KEY, $ok, $message, ['admin_url' => $adminUrl]);
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, admin_url: string}|null
     */
    public function lastReload(): ?array
    {
        return $this->readRun(self::LAST_RELOAD_KEY, ['admin_url' => true]);
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastComposerRun(): ?array
    {
        return $this->readRun(self::COMPOSER_RUN_KEY, ['pm' => false]);
    }

    /**
     * @return array{attempted_at: string, ok: bool, message: string, pm: string|null}|null
     */
    public function lastFrontendRun(): ?array
    {
        return $this->readRun(self::FRONTEND_RUN_KEY, ['pm' => false]);
    }

    /**
     * Record the run shown in the Deployment page's run box so its outcome and time
     * survive a page reload or a brand-new session.
     *
     * @param  list<string>  $log
     */
    public function rememberDeploymentRun(array $log, string $status): void
    {
        $this->settings->set(self::DEPLOYMENT_RUN_KEY, [
            'attempted_at' => now()->utc()->toIso8601String(),
            'status' => $status,
            'summary' => $log === [] ? '' : (string) $log[array_key_last($log)],
            'log' => array_values($log),
        ]);
    }

    /**
     * @return array{attempted_at: string, status: string, summary: string, log: list<string>}|null
     */
    public function lastDeploymentRun(): ?array
    {
        $record = $this->settings->get(self::DEPLOYMENT_RUN_KEY);

        if (! is_array($record)) {
            return null;
        }

        $attemptedAt = $record['attempted_at'] ?? null;
        $status = $record['status'] ?? null;

        if (! is_string($attemptedAt) || ! is_string($status)) {
            return null;
        }

        return [
            'attempted_at' => $attemptedAt,
            'status' => $status,
            'summary' => is_string($record['summary'] ?? null) ? $record['summary'] : '',
            'log' => array_values(array_filter(
                is_array($record['log'] ?? null) ? $record['log'] : [],
                'is_string',
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function rememberRun(string $key, bool $ok, string $message, array $extra = []): void
    {
        $this->settings->set($key, array_merge([
            'attempted_at' => now()->utc()->toIso8601String(),
            'ok' => $ok,
            'message' => $message,
        ], $extra));
    }

    /**
     * @param  array<string, bool>  $stringFields  field => required
     * @return array<string, bool|string|null>|null
     */
    private function readRun(string $key, array $stringFields = []): ?array
    {
        $record = $this->settings->get($key);
        $attemptedAt = is_array($record) ? ($record['attempted_at'] ?? null) : null;
        $message = is_array($record) ? ($record['message'] ?? null) : null;

        if (! is_array($record) || ! is_string($attemptedAt) || ! is_string($message)) {
            return null;
        }

        $run = [
            'attempted_at' => $attemptedAt,
            'ok' => ($record['ok'] ?? false) === true,
            'message' => $message,
        ];

        foreach ($stringFields as $field => $required) {
            $value = $record[$field] ?? null;

            if ($required && ! is_string($value)) {
                return null;
            }

            $run[$field] = is_string($value) ? $value : null;
        }

        return $run;
    }
}
