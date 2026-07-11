<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\VerifiedDataSharePackage;
use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Models\DataShareReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DataSharePackageInbox
{
    public function __construct(
        private readonly DataSharePrivateStorage $storage,
        private readonly DataSharePackageVerifier $verifier,
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataShareEventRecorder $events,
    ) {}

    public function receiveFromProtectedPath(
        string $sourcePath,
        DataSharePackageExpectation $expected,
    ): DataShareReceipt {
        $verified = $this->verifier->verifyPath($sourcePath, $expected);
        $existing = DataShareReceipt::query()->where('package_id', $expected->packageId)->first();

        if ($existing !== null) {
            return $this->matchingExistingReceipt($existing, $verified, $expected);
        }

        try {
            return Cache::lock('base:data-share:receive:'.$expected->packageId, 120)->block(
                30,
                fn (): DataShareReceipt => $this->admitVerified($sourcePath, $verified, $expected),
            );
        } catch (LockTimeoutException) {
            throw DataSharePackageException::receiveInProgress($expected->packageId);
        }
    }

    private function admitVerified(
        string $sourcePath,
        VerifiedDataSharePackage $verified,
        DataSharePackageExpectation $expected,
    ): DataShareReceipt {
        $existing = DataShareReceipt::query()->where('package_id', $expected->packageId)->first();

        if ($existing !== null) {
            return $this->matchingExistingReceipt($existing, $verified, $expected);
        }

        $destinationPath = $this->storage->incomingPath($expected->packageId);
        $disk = $this->storage->disk();
        $wroteDestination = false;

        try {
            return DB::transaction(function () use ($sourcePath, $destinationPath, $expected, $verified, &$wroteDestination): DataShareReceipt {
                if ($sourcePath !== $destinationPath) {
                    $this->copyPackage($sourcePath, $destinationPath);
                    $wroteDestination = true;
                }

                $received = $this->verifier->verifyPath($destinationPath, $expected);

                if (! hash_equals($verified->sha256, $received->sha256)) {
                    throw DataSharePackageException::receiveFailed($destinationPath);
                }

                return $this->storeReceipt($received, $destinationPath, $expected);
            });
        } catch (\Throwable $e) {
            if ($wroteDestination) {
                $disk->delete($destinationPath);
            }

            throw $e;
        }
    }

    private function matchingExistingReceipt(
        DataShareReceipt $receipt,
        VerifiedDataSharePackage $verified,
        DataSharePackageExpectation $expected,
    ): DataShareReceipt {
        if (hash_equals($receipt->package_sha256, $verified->sha256)
            && hash_equals($receipt->offer_id, $expected->offerId)
            && hash_equals($receipt->source_instance_id, $expected->source->id)
            && hash_equals($receipt->scope_name, $expected->scope)
            && hash_equals($receipt->target_instance_id, $this->instances->current()->id)) {
            return $receipt;
        }

        throw DataSharePackageException::receiptBindingCollision($expected->packageId);
    }

    private function copyPackage(string $sourcePath, string $destinationPath): void
    {
        $disk = $this->storage->disk();
        $source = $disk->readStream($sourcePath);

        try {
            if ($source === false || ! $disk->put($destinationPath, $source)) {
                throw DataSharePackageException::receiveFailed($destinationPath);
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    private function storeReceipt(
        VerifiedDataSharePackage $verified,
        string $path,
        DataSharePackageExpectation $expected,
    ): DataShareReceipt {
        $manifest = $verified->manifest;
        $receipt = DataShareReceipt::query()->create([
            'package_id' => $manifest['package_id'],
            'package_sha256' => $verified->sha256,
            'package_path' => $path,
            'source_instance_id' => $manifest['source']['id'],
            'source_role' => $manifest['source']['role'],
            'target_instance_id' => $this->instances->current()->id,
            'scope_name' => $manifest['scope']['name'],
            'offer_id' => $expected->offerId,
            'status' => 'verified',
            'received_at' => now('UTC'),
            'expires_at' => CarbonImmutable::parse($manifest['expires_at'], 'UTC'),
            'metadata' => [
                'bytes' => $verified->bytes,
                'counts' => $manifest['counts'],
                'payloads' => $manifest['payloads'],
                'tables' => $manifest['scope']['tables'],
                'offer_id' => $expected->offerId,
                'source_name' => $manifest['source']['name'],
            ],
        ]);

        $this->events->recordReceipt('received', $receipt, [
            'bytes' => $verified->bytes,
            'counts' => $manifest['counts'],
            'package_sha256' => $verified->sha256,
            'offer_id' => $expected->offerId,
        ]);

        return $receipt;
    }
}
