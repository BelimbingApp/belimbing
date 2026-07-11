<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\VerifiedDataSharePackage;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataSharePackageException;
use Carbon\CarbonImmutable;

class DataSharePackageVerifier
{
    public function __construct(
        private readonly DataSharePrivateStorage $storage,
        private readonly DataSharePackageReader $reader,
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataShareDirectionPolicy $directions,
        private readonly DataShareScopeCatalog $catalog,
        private readonly DataShareSettings $settings,
    ) {}

    public function verifyPath(string $path, DataSharePackageExpectation $expected): VerifiedDataSharePackage
    {
        $disk = $this->storage->disk();
        $maximum = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);

        if (! $disk->exists($path) || $expected->bytes < 1 || $expected->bytes > $maximum) {
            throw DataSharePackageException::packageTooLarge($maximum);
        }

        if ($disk->size($path) !== $expected->bytes) {
            throw DataSharePackageException::invalidPackage(__('the package size does not match its transfer offer.'));
        }

        $headerStream = $disk->readStream($path);

        if ($headerStream === false) {
            throw DataSharePackageException::invalidPackage(__('the package cannot be read.'));
        }

        try {
            $manifest = $this->reader->manifest($headerStream);
        } finally {
            fclose($headerStream);
        }

        $this->assertPolicy($manifest, $expected);
        $stream = $disk->readStream($path);

        if ($stream === false) {
            throw DataSharePackageException::invalidPackage(__('the package cannot be reopened.'));
        }

        try {
            $verified = $this->reader->inspect($stream);
        } finally {
            fclose($stream);
        }

        if ($verified->bytes !== $expected->bytes
            || ! hash_equals($expected->packageSha256, $verified->sha256)) {
            throw DataSharePackageException::invalidPackage(__('the package bytes do not match their transfer offer.'));
        }

        return $verified;
    }

    /** @param array<string, mixed> $manifest */
    private function assertPolicy(array $manifest, DataSharePackageExpectation $expected): void
    {
        $current = $this->instances->current();
        $sourceRole = DataShareInstanceRole::tryFrom((string) ($manifest['source']['role'] ?? ''));

        if ($sourceRole === null) {
            throw DataSharePackageException::invalidPackage(__('the source role is invalid.'));
        }

        $source = new DataShareInstanceIdentity(
            id: (string) ($manifest['source']['id'] ?? ''),
            name: (string) ($manifest['source']['name'] ?? $manifest['source']['id'] ?? ''),
            role: $sourceRole,
        );
        $scopeName = (string) ($manifest['scope']['name'] ?? '');

        try {
            $expectedExpiry = CarbonImmutable::parse($expected->expiresAt, 'UTC')->toIso8601String();
            $manifestExpiry = CarbonImmutable::parse((string) ($manifest['expires_at'] ?? ''), 'UTC')->toIso8601String();
        } catch (\Throwable) {
            throw DataSharePackageException::invalidPackage(__('package timestamps are invalid.'));
        }

        if (! hash_equals($expected->offerId, (string) ($manifest['transfer_offer_id'] ?? ''))
            || ! hash_equals($expected->packageId, (string) ($manifest['package_id'] ?? ''))
            || ! hash_equals($expected->source->id, $source->id)
            || ! hash_equals($expected->source->name, $source->name)
            || $expected->source->role !== $source->role
            || ! hash_equals($expected->scope, $scopeName)
            || CanonicalJson::encode($expected->counts) !== CanonicalJson::encode($manifest['counts'] ?? null)
            || ! hash_equals($expectedExpiry, $manifestExpiry)) {
            throw DataSharePackageException::invalidPackage(__('the package does not match its transfer offer.'));
        }

        $this->catalog->scope($scopeName, $manifest['scope']['tables']);
        $this->directions->assertAllowed($source, $current);

        try {
            $createdAt = CarbonImmutable::parse($manifest['created_at'], 'UTC');
            $expiresAt = CarbonImmutable::parse($manifest['expires_at'], 'UTC');
        } catch (\Throwable) {
            throw DataSharePackageException::invalidPackage(__('package timestamps are invalid.'));
        }

        $now = CarbonImmutable::now('UTC');

        if ($expiresAt->lessThanOrEqualTo($now)) {
            throw DataSharePackageException::expired((string) $manifest['package_id']);
        }

        if ($createdAt->greaterThan($now->addMinutes(5)) || $expiresAt->lessThanOrEqualTo($createdAt)) {
            throw DataSharePackageException::invalidPackage(__('package creation and expiry times are inconsistent.'));
        }
    }
}
