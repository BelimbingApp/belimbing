<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\Exceptions\BridgePolicyException;
use App\Base\Database\Exceptions\BridgeTransportException;
use App\Base\Database\Models\BridgeReceiveGrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class BridgeReceiveGrantManager
{
    public function __construct(
        private readonly BridgeScopeCatalog $catalog,
        private readonly BridgeInstanceIdentityResolver $instances,
        private readonly BridgeDirectionPolicy $directions,
        private readonly BridgeEventRecorder $events,
        private readonly BridgeSettings $settings,
    ) {}

    public function issue(
        BridgeInstanceIdentity $expectedSource,
        string $scopeName,
        ?int $actorId = null,
    ): BridgeReceiveGrantBundle {
        $target = $this->instances->current();
        $this->directions->assertAllowed($expectedSource, $target);
        $scope = $this->catalog->scope($scopeName);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $grantId = Str::lower((string) Str::ulid());
        $endpoints = $this->receiveEndpoints($grantId);
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes(max(
            1,
            $this->settings->integer('bridge.receive_grants.expiry_minutes', 15, 1, 1440),
        ));
        $grant = BridgeReceiveGrant::query()->create([
            'grant_id' => $grantId,
            'secret_hash' => hash('sha256', $secret),
            'issued_by_actor_id' => $actorId ?? auth()->id(),
            'expected_source_instance_id' => $expectedSource->id,
            'expected_source_role' => $expectedSource->role->value,
            'target_instance_id' => $target->id,
            'target_role' => $target->role->value,
            'scope_name' => $scope->name,
            'max_bytes' => $this->settings->integer('bridge.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647),
            'status' => 'issued',
            'expires_at' => $expiresAt,
        ]);
        $this->events->recordGrant('grant_issued', $grant);

        return new BridgeReceiveGrantBundle(
            endpoint: $endpoints[0],
            endpoints: $endpoints,
            grantId: $grant->grant_id,
            secret: $secret,
            expectedSource: $expectedSource,
            target: $target,
            scope: $scope->name,
            maxBytes: $grant->max_bytes,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    /** @return list<string> */
    private function receiveEndpoints(string $grantId): array
    {
        $baseUrls = $this->settings->stringList('bridge.receive_grants.base_urls');

        if ($baseUrls === []) {
            $endpoint = Route::has('data-bridge.receive')
                ? route('data-bridge.receive', ['grantId' => $grantId])
                : rtrim((string) config('app.url'), '/').'/data-bridge/receive/'.$grantId;

            return [$this->assertEndpoint($endpoint)];
        }

        if (count($baseUrls) > 5) {
            throw BridgePolicyException::tooManyReceiveBaseUrls(5);
        }

        return array_values(array_unique(array_map(
            fn (mixed $baseUrl): string => $this->assertEndpoint(
                rtrim(trim((string) $baseUrl), '/').'/data-bridge/receive/'.$grantId,
            ),
            $baseUrls,
        )));
    }

    private function assertEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw BridgePolicyException::invalidReceiveBaseUrl($endpoint);
        }

        return $endpoint;
    }

    public function authenticate(string $grantId, string $secret): BridgeReceiveGrant
    {
        $grant = BridgeReceiveGrant::query()->where('grant_id', $grantId)->first();

        if ($grant === null
            || $grant->status !== 'issued'
            || $grant->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            || $secret === ''
            || ! hash_equals($grant->secret_hash, hash('sha256', $secret))) {
            if ($grant?->status === 'issued' && $grant->expires_at->isPast()) {
                $grant->forceFill(['status' => 'expired'])->save();
                $this->events->recordGrant('grant_expired', $grant);
            }

            throw BridgeTransportException::invalidReceiveGrant();
        }

        return $grant;
    }

    public function revoke(BridgeReceiveGrant $grant): void
    {
        if ($grant->status !== 'issued') {
            throw BridgePolicyException::grantNotRevocable($grant->status);
        }

        $grant->forceFill([
            'status' => 'revoked',
            'revoked_at' => CarbonImmutable::now('UTC'),
        ])->save();
        $this->events->recordGrant('grant_revoked', $grant);
    }

    public function consume(int $grantDatabaseId, string $packageSha256): BridgeReceiveGrant
    {
        return DB::transaction(function () use ($grantDatabaseId, $packageSha256): BridgeReceiveGrant {
            $consumedAt = CarbonImmutable::now('UTC');
            $updated = BridgeReceiveGrant::query()
                ->whereKey($grantDatabaseId)
                ->where('status', 'issued')
                ->where('expires_at', '>', $consumedAt)
                ->update([
                    'consumed_package_sha256' => $packageSha256,
                    'status' => 'consumed',
                    'consumed_at' => $consumedAt,
                    'updated_at' => $consumedAt,
                ]);

            if ($updated !== 1) {
                throw BridgeTransportException::grantConflict();
            }

            $grant = BridgeReceiveGrant::query()->findOrFail($grantDatabaseId);
            $this->events->recordGrant('grant_consumed', $grant, ['package_sha256' => $packageSha256]);

            return $grant;
        });
    }
}
