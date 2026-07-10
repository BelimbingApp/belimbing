<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\DTO\Bridge\VerifiedBridgePackage;
use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Exceptions\BridgePackageException;
use App\Base\Database\Models\BridgeReceiveGrant;
use Carbon\CarbonImmutable;

class BridgePackageVerifier
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgePackageReader $reader,
        private readonly BridgeInstanceIdentityResolver $instances,
        private readonly BridgeDirectionPolicy $directions,
        private readonly BridgeScopeCatalog $catalog,
        private readonly BridgeSettings $settings,
    ) {}

    public function verifyPath(string $path, BridgeReceiveGrant $grant): VerifiedBridgePackage
    {
        $disk = $this->storage->disk();
        $max = min(
            $this->settings->integer('bridge.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647),
            (int) $grant->max_bytes,
        );

        if (! $disk->exists($path) || $disk->size($path) > $max) {
            throw BridgePackageException::packageTooLarge($max);
        }

        $headerStream = $disk->readStream($path);

        if ($headerStream === false) {
            throw BridgePackageException::invalidPackage(__('the package cannot be read.'));
        }

        try {
            $manifest = $this->reader->manifest($headerStream);
        } finally {
            fclose($headerStream);
        }

        $this->assertPolicy($manifest, $grant);
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw BridgePackageException::invalidPackage(__('the package cannot be reopened.'));
        }

        try {
            $verified = $this->reader->inspect($stream);
        } finally {
            fclose($stream);
        }

        if ($grant->consumed_package_sha256 !== null
            && ! hash_equals($grant->consumed_package_sha256, $verified->sha256)) {
            throw BridgePackageException::invalidPackage(__('the package no longer matches its consumed receive grant.'));
        }

        if ($verified->bytes !== $disk->size($path)) {
            throw BridgePackageException::invalidPackage(__('the package size changed during verification.'));
        }

        return $verified;
    }

    /** @param array<string, mixed> $manifest */
    private function assertPolicy(array $manifest, BridgeReceiveGrant $grant): void
    {
        $current = $this->instances->current();
        $target = $manifest['target'];

        if (! hash_equals($current->id, (string) $target['id'])
            || $current->role->value !== ($target['role'] ?? null)
            || $grant->target_instance_id !== $current->id
            || $grant->target_role !== $current->role->value) {
            throw BridgePackageException::wrongTarget($current->id, (string) ($target['id'] ?? 'missing'));
        }

        $sourceRole = BridgeInstanceRole::tryFrom((string) ($manifest['source']['role'] ?? ''));

        if ($sourceRole === null) {
            throw BridgePackageException::invalidPackage(__('the source role is invalid.'));
        }

        $source = new BridgeInstanceIdentity(
            id: (string) $manifest['source']['id'],
            name: (string) ($manifest['source']['name'] ?? $manifest['source']['id']),
            role: $sourceRole,
        );
        $scopeName = (string) $manifest['scope']['name'];

        if (! hash_equals($grant->grant_id, (string) $manifest['receive_grant_id'])
            || ! hash_equals($grant->expected_source_instance_id, $source->id)
            || $grant->expected_source_role !== $source->role->value
            || ! hash_equals($grant->scope_name, $scopeName)) {
            throw BridgePackageException::invalidPackage(__('the package does not match its one-time receive grant.'));
        }

        $this->catalog->scope($scopeName, $manifest['scope']['tables']);
        $this->directions->assertAllowed($source, $current);

        try {
            $createdAt = CarbonImmutable::parse($manifest['created_at'], 'UTC');
            $expiresAt = CarbonImmutable::parse($manifest['expires_at'], 'UTC');
        } catch (\Throwable) {
            throw BridgePackageException::invalidPackage(__('package timestamps are invalid.'));
        }

        $now = CarbonImmutable::now('UTC');

        if ($expiresAt->lessThanOrEqualTo($now)) {
            throw BridgePackageException::expired((string) $manifest['package_id']);
        }

        if ($createdAt->greaterThan($now->addMinutes(5)) || $expiresAt->lessThanOrEqualTo($createdAt)) {
            throw BridgePackageException::invalidPackage(__('package creation and expiry times are inconsistent.'));
        }
    }
}
