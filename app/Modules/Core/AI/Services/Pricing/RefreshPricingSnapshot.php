<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Exceptions\PricingSnapshotRefreshException;
use App\Modules\Core\AI\Models\AiPricingSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class RefreshPricingSnapshot
{
    private const SOURCE = 'litellm';

    private const DEFAULT_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * @return array<string, mixed>
     */
    public function refresh(?string $url = null, ?Carbon $snapshotDate = null): array
    {
        $url ??= $this->snapshotUrl();
        $snapshotDate ??= now();

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                return $this->fallbackResult("HTTP {$response->status()} from pricing snapshot source.");
            }

            $payload = $response->json();

            if (! is_array($payload)) {
                return $this->fallbackResult('Pricing snapshot source returned invalid JSON.');
            }

            return $this->importPayload($payload, Carbon::parse($snapshotDate), $url);
        } catch (Throwable $e) {
            return $this->fallbackResult($e->getMessage(), $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        if (! Schema::hasTable('ai_pricing_snapshots')) {
            return $this->emptyStats();
        }

        $latestDate = $this->latestSnapshotDate();

        if ($latestDate === null) {
            return $this->emptyStats();
        }

        $query = AiPricingSnapshot::query()
            ->where('source', self::SOURCE)
            ->whereDate('snapshot_date', $latestDate);

        $lastRefreshedAt = (clone $query)->max('updated_at');

        return [
            'source' => self::SOURCE,
            'snapshot_date' => $latestDate->toDateString(),
            'last_refreshed_at' => $lastRefreshedAt !== null ? Carbon::parse($lastRefreshedAt)->toIso8601String() : null,
            'model_count' => (clone $query)->distinct()->count('model'),
            'row_count' => (clone $query)->count(),
            'age_days' => $latestDate->diffInDays(now()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStats(): array
    {
        return [
            'source' => self::SOURCE,
            'snapshot_date' => null,
            'last_refreshed_at' => null,
            'model_count' => 0,
            'row_count' => 0,
            'age_days' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function importPayload(array $payload, Carbon $snapshotDate, string $url): array
    {
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($payload, $snapshotDate, $url, &$imported, &$skipped): void {
            foreach ($payload as $model => $raw) {
                if (! is_string($model) || $model === 'sample_spec' || ! is_array($raw)) {
                    $skipped++;

                    continue;
                }

                $inputCost = $raw['input_cost_per_token'] ?? null;
                $outputCost = $raw['output_cost_per_token'] ?? null;

                if (! is_numeric($inputCost) || ! is_numeric($outputCost)) {
                    $skipped++;

                    continue;
                }

                $this->upsertSnapshotRow(
                    model: $model,
                    provider: $this->providerFrom($raw),
                    snapshotDate: $snapshotDate,
                    attributes: [
                        'input_cents_per_token' => $this->dollarsToCentsPerToken($inputCost),
                        'cached_input_cents_per_token' => $this->cachedInputCentsPerToken($raw),
                        'output_cents_per_token' => $this->dollarsToCentsPerToken($outputCost),
                        'source_version' => $snapshotDate->toDateString(),
                        'raw' => [
                            'source_url' => $url,
                            'mode' => $raw['mode'] ?? null,
                            'supports_prompt_caching' => $raw['supports_prompt_caching'] ?? null,
                            'litellm_provider' => $raw['litellm_provider'] ?? null,
                        ],
                    ],
                );

                $imported++;
            }
        });

        $stats = $this->stats();

        return [
            ...$stats,
            'refreshed' => true,
            'used_fallback' => false,
            'imported_count' => $imported,
            'skipped_count' => $skipped,
            'source_url' => $url,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertSnapshotRow(string $model, ?string $provider, Carbon $snapshotDate, array $attributes): void
    {
        $query = AiPricingSnapshot::query()
            ->where('source', self::SOURCE)
            ->where('model', $model)
            ->whereDate('snapshot_date', $snapshotDate->toDateString());

        if ($provider === null) {
            $query->whereNull('provider');
        } else {
            $query->where('provider', $provider);
        }

        $snapshot = $query->first();

        if ($snapshot === null) {
            AiPricingSnapshot::query()->create([
                'source' => self::SOURCE,
                'provider' => $provider,
                'model' => $model,
                'snapshot_date' => $snapshotDate->toDateString(),
                ...$attributes,
            ]);

            return;
        }

        $snapshot->fill($attributes);
        $snapshot->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackResult(string $reason, ?Throwable $previous = null): array
    {
        $stats = $this->stats();

        if ($stats['row_count'] > 0) {
            return [
                ...$stats,
                'refreshed' => false,
                'used_fallback' => true,
                'imported_count' => 0,
                'skipped_count' => 0,
                'error' => $reason,
            ];
        }

        throw PricingSnapshotRefreshException::noFallback(
            'Pricing snapshot refresh failed and no previous snapshot is available: '.$reason,
            $previous,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function providerFrom(array $raw): ?string
    {
        $provider = $raw['litellm_provider'] ?? null;

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function cachedInputCentsPerToken(array $raw): ?string
    {
        foreach (['cache_read_input_token_cost', 'input_cost_per_token_cache_hit'] as $key) {
            $cost = $raw[$key] ?? null;

            if (is_numeric($cost)) {
                return $this->dollarsToCentsPerToken($cost);
            }
        }

        return null;
    }

    private function dollarsToCentsPerToken(mixed $dollars): string
    {
        return sprintf('%.12F', (float) $dollars * 100);
    }

    private function latestSnapshotDate(): ?Carbon
    {
        $latest = AiPricingSnapshot::query()
            ->where('source', self::SOURCE)
            ->max('snapshot_date');

        return $latest !== null ? Carbon::parse($latest) : null;
    }

    private function snapshotUrl(): string
    {
        $configured = (string) config('ai.pricing.litellm_snapshot_url', '');

        return $configured !== '' ? $configured : self::DEFAULT_URL;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('ai.pricing.refresh_timeout_seconds', 30));
    }
}
