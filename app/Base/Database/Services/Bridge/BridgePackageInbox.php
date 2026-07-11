<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\VerifiedBridgePackage;
use App\Base\Database\Exceptions\BridgePackageException;
use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Models\BridgeReceiveGrant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class BridgePackageInbox
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgePackageVerifier $verifier,
        private readonly BridgeReceiveGrantManager $grants,
        private readonly BridgeEventRecorder $events,
    ) {}

    public function receiveFromProtectedPath(
        string $sourcePath,
        BridgeReceiveGrant $grant,
    ): BridgeReceipt {
        $verified = $this->verifier->verifyPath($sourcePath, $grant);
        $manifest = $verified->manifest;
        $existing = BridgeReceipt::query()->where('package_id', $manifest['package_id'])->first();

        if ($existing !== null) {
            return $this->matchingExistingReceipt($existing, $verified, $grant);
        }

        $destinationPath = $this->storage->incomingPath((string) $manifest['package_id']);
        $disk = $this->storage->disk();
        $wroteDestination = false;

        try {
            return DB::transaction(function () use (
                $sourcePath,
                $destinationPath,
                $grant,
                $verified,
                &$wroteDestination,
            ): BridgeReceipt {
                $consumedGrant = $this->grants->consume($grant->id, $verified->sha256);

                if ($sourcePath !== $destinationPath) {
                    $this->copyPackage($sourcePath, $destinationPath);
                    $wroteDestination = true;
                }

                $received = $this->verifier->verifyPath($destinationPath, $consumedGrant);

                if (! hash_equals($verified->sha256, $received->sha256)) {
                    throw BridgePackageException::receiveFailed($destinationPath);
                }

                return $this->storeReceipt($received, $destinationPath, $consumedGrant);
            });
        } catch (\Throwable $e) {
            if ($wroteDestination) {
                $disk->delete($destinationPath);
            }

            throw $e;
        }
    }

    private function matchingExistingReceipt(
        BridgeReceipt $receipt,
        VerifiedBridgePackage $verified,
        BridgeReceiveGrant $grant,
    ): BridgeReceipt {
        if (hash_equals($receipt->package_sha256, $verified->sha256)
            && $receipt->receive_grant_id === $grant->id) {
            return $receipt;
        }

        throw BridgePackageException::packageIdCollision((string) $verified->manifest['package_id']);
    }

    private function copyPackage(string $sourcePath, string $destinationPath): void
    {
        $disk = $this->storage->disk();
        $source = $disk->readStream($sourcePath);

        try {
            if ($source === false || ! $disk->put($destinationPath, $source)) {
                throw BridgePackageException::receiveFailed($destinationPath);
            }
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    private function storeReceipt(
        VerifiedBridgePackage $verified,
        string $path,
        BridgeReceiveGrant $grant,
    ): BridgeReceipt {
        $manifest = $verified->manifest;

        $receipt = BridgeReceipt::query()->create([
            'package_id' => $manifest['package_id'],
            'package_sha256' => $verified->sha256,
            'package_path' => $path,
            'source_instance_id' => $manifest['source']['id'],
            'source_role' => $manifest['source']['role'],
            'target_instance_id' => $manifest['target']['id'],
            'scope_name' => $manifest['scope']['name'],
            'receive_grant_id' => $grant->id,
            'status' => 'verified',
            'received_at' => now('UTC'),
            'expires_at' => CarbonImmutable::parse($manifest['expires_at'], 'UTC'),
            'metadata' => [
                'bytes' => $verified->bytes,
                'counts' => $manifest['counts'],
                'payloads' => $manifest['payloads'],
                'tables' => $manifest['scope']['tables'],
                'grant_id' => $grant->grant_id,
            ],
        ]);

        $this->events->recordReceipt('received', $receipt, [
            'bytes' => $verified->bytes,
            'counts' => $manifest['counts'],
            'package_sha256' => $verified->sha256,
            'grant_id' => $grant->grant_id,
        ]);

        return $receipt;
    }
}
