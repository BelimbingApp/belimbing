<?php

namespace App\Base\Software\Services;

use App\Base\Support\PhpCli;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

final class DeploymentWorkerReloader
{
    private const ADMIN_CONNECT_TIMEOUT_SECONDS = 2;

    private const ADMIN_REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly DeploymentAdminEndpointResolver $adminEndpoints,
        private readonly DeploymentRunHistory $history,
    ) {}

    /**
     * Graceful, non-elevated worker reload: probe the Caddy admin API for a
     * FrankenPHP worker config, POST FrankenPHP's worker restart endpoint, then
     * queue:restart. The scheduler self-refreshes per run.
     *
     * @return list<string>
     */
    public function reload(bool $clearRuntimeCaches = true): array
    {
        $log = [];

        if ($clearRuntimeCaches) {
            $log[] = $this->clearRuntimeCaches();
        }

        $log[] = $this->warmRuntimeBootstrap();

        $webReloaded = false;
        $reloadMessage = '';
        $adminUrl = '';
        $candidates = $this->adminEndpoints->candidates();

        Log::debug('FrankenPHP worker reload probing admin API candidates.', [
            'candidates' => array_map(
                static fn (array $candidate): string => "{$candidate[0]}:{$candidate[1]}",
                $candidates,
            ),
        ]);

        foreach ($candidates as [$host, $port]) {
            $configUrl = "http://{$host}:{$port}/config/apps/frankenphp";
            $restartUrl = "http://{$host}:{$port}/frankenphp/workers/restart";
            $adminUrl = $configUrl;

            try {
                $config = $this->sendFrankenPhpAdminRequest(
                    fn (): Response => $this->frankenPhpAdminHttp()->get($configUrl),
                );

                if ($this->frankenPhpWorkerConfigPresent($config)) {
                    $adminUrl = $restartUrl;
                    $restart = $this->sendFrankenPhpAdminRequest(
                        fn (): Response => $this->frankenPhpAdminHttp()->post($restartUrl),
                    );

                    if ($restart->successful()) {
                        $webReloaded = true;
                        $reloadMessage = (string) __('Web workers reloaded.');
                        Log::debug('FrankenPHP worker reload succeeded.', ['admin_url' => $restartUrl]);

                        break;
                    }

                    $reloadMessage = (string) __('Warning: web workers were not reloaded; the FrankenPHP admin API returned HTTP :status. Running workers may keep old code until they restart.', ['status' => $restart->status()]);
                    Log::debug('FrankenPHP worker restart failed.', [
                        'admin_url' => $restartUrl,
                        'status' => $restart->status(),
                    ]);

                    continue;
                }

                $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url did not expose worker config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.', ['url' => $configUrl]);
                Log::debug('FrankenPHP worker reload GET returned no worker config.', [
                    'admin_url' => $configUrl,
                    'status' => $config->status(),
                ]);
            } catch (\Throwable $exception) {
                $reloadMessage = (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url could not be reached: :message', ['url' => $adminUrl, 'message' => $exception->getMessage()]);
                Log::debug('FrankenPHP worker reload request failed.', [
                    'admin_url' => $adminUrl,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $log[] = $reloadMessage;

        Artisan::call('queue:restart');
        $log[] = (string) __('Queue restart signaled.');
        $this->history->rememberReload($webReloaded, $reloadMessage, $adminUrl);

        return $log;
    }

    private function frankenPhpAdminHttp(): PendingRequest
    {
        return Http::connectTimeout(self::ADMIN_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::ADMIN_REQUEST_TIMEOUT_SECONDS);
    }

    private function frankenPhpWorkerConfigPresent(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $config = $response->json();

        return is_array($config) && array_key_exists('workers', $config);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function sendFrankenPhpAdminRequest(callable $request): Response
    {
        try {
            return $request();
        } catch (\Throwable $exception) {
            if (! $this->isHttpTimeout($exception)) {
                throw $exception;
            }

            return $request();
        }
    }

    private function isHttpTimeout(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'cURL error 28')
            || str_contains(strtolower($message), 'timed out');
    }

    private function clearRuntimeCaches(): string
    {
        Artisan::call('optimize:clear');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return (string) __('Runtime caches cleared.');
    }

    /**
     * FrankenPHP boots many workers concurrently. When the provider list changes
     * (for example, enabling/disabling a domain), letting every worker compile
     * bootstrap/cache/services.php at once is unreliable on Windows. Warm it once
     * in a normal CLI process before asking FrankenPHP to respawn the pool.
     */
    private function warmRuntimeBootstrap(): string
    {
        $warm = Process::path(base_path())
            ->timeout(60)
            ->run(PhpCli::current()->artisan(['about', '--only=environment']));

        if ($warm->successful()) {
            return (string) __('Runtime bootstrap warmed.');
        }

        $output = trim($warm->output()."\n".$warm->errorOutput());

        return (string) __('Warning: runtime bootstrap warmup failed before worker reload: :message', [
            'message' => $output !== '' ? $output : __('process exited with code :code', ['code' => $warm->exitCode()]),
        ]);
    }
}
