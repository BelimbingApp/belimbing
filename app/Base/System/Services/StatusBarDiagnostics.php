<?php

namespace App\Base\System\Services;

use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class StatusBarDiagnostics
{
    private const PROVIDER_FAILURE_LOG_DEDUP_SECONDS = 900;

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @return Collection<int, StatusBarDiagnostic>
     */
    public function forUser(Authenticatable $user): Collection
    {
        return collect($this->app->tagged(StatusBarDiagnosticProvider::CONTAINER_TAG))
            ->flatMap(fn (StatusBarDiagnosticProvider $provider): array => $this->collectFromProvider($provider, $user))
            ->filter(fn (mixed $diagnostic): bool => $diagnostic instanceof StatusBarDiagnostic)
            ->unique(fn (StatusBarDiagnostic $diagnostic): string => $diagnostic->id)
            ->sort(function (StatusBarDiagnostic $a, StatusBarDiagnostic $b): int {
                return ($b->severityRank() <=> $a->severityRank())
                    ?: strnatcasecmp($a->source, $b->source)
                    ?: strnatcasecmp($a->summary, $b->summary);
            })
            ->values();
    }

    /**
     * @return array<int, StatusBarDiagnostic>
     */
    private function collectFromProvider(StatusBarDiagnosticProvider $provider, Authenticatable $user): array
    {
        try {
            return collect($provider->diagnosticsFor($user))
                ->filter(fn (mixed $diagnostic): bool => $diagnostic instanceof StatusBarDiagnostic)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->logProviderFailure($provider, $e);

            return [];
        }
    }

    private function logProviderFailure(StatusBarDiagnosticProvider $provider, \Throwable $e): void
    {
        $providerClass = $provider::class;
        $fingerprint = sha1($providerClass.'|'.$e::class.'|'.$e->getMessage());

        try {
            $shouldLog = Cache::add(
                'blb.status-bar.diagnostic-provider-failure.'.$fingerprint,
                true,
                now()->addSeconds(self::PROVIDER_FAILURE_LOG_DEDUP_SECONDS),
            );
        } catch (\Throwable) {
            $shouldLog = true;
        }

        if (! $shouldLog) {
            return;
        }

        logger()->warning('Status bar diagnostic provider failed.', [
            'provider' => $providerClass,
            'error' => $e->getMessage(),
        ]);
    }
}
