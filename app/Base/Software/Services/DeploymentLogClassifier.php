<?php

namespace App\Base\Software\Services;

final class DeploymentLogClassifier
{
    /**
     * Opening words of DeploymentWorkerReloader's "nothing was running" notice.
     * Classification here matches on English line prefixes throughout (see the
     * 'Warning:'/'FAILED:' checks below); this one is pinned to a constant so the
     * reloader and the completion line cannot drift apart.
     */
    public const NO_RUNTIME_PREFIX = 'No running web workers were found';

    /**
     * @param  list<string>  $log
     */
    public static function hasError(array $log): bool
    {
        return collect($log)->contains(function (string $line): bool {
            $lower = strtolower($line);

            return str_starts_with($line, 'FAILED:')
                || str_contains($lower, ' install failed:')
                || str_contains($lower, ' build failed:')
                || str_contains($lower, ' refresh failed:');
        });
    }

    /**
     * @param  list<string>  $log
     */
    public static function hasWarning(array $log): bool
    {
        return collect($log)->contains(fn (string $line): bool => str_starts_with($line, 'Warning:')
            || str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify'));
    }

    /**
     * The reload found no runtime to reload because the app was stopped. Not a
     * warning — with no worker pool there is no stale code to serve — but the
     * completion line must not claim workers were reloaded either.
     *
     * @param  list<string>  $log
     */
    public static function hasNoRuntimeNotice(array $log): bool
    {
        return collect($log)->contains(fn (string $line): bool => str_starts_with($line, self::NO_RUNTIME_PREFIX));
    }

    /**
     * @param  list<string>  $log
     */
    public static function hasVerificationWarning(array $log): bool
    {
        return collect($log)->contains(fn (string $line): bool => str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify'));
    }
}
