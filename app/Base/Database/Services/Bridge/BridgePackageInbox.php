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
            if (hash_equals($existing->package_sha256, $verified->sha256)
                && $existing->receive_grant_id === $grant->id) {
                return $existing;
            }

            throw BridgePackageException::packageIdCollision((string) $manifest['package_id']);
        }

        $destinationPath = $this->storage->incomingPath((string) $manifest['package_id']);
        $disk = $this->storage->disk();
        $wroteDestination = false;

        try {
            return DB::transaction(function () use (
                $sourcePath,
                $destinationPath,
                $disk,
                $grant,
                $verified,
                &$wroteDestination,
            ): BridgeReceipt {
                $consumedGrant = $this->grants->consume($grant->id, $verified->sha256);

                if ($sourcePath !== $destinationPath) {
                    $source = $disk->readStream($sourcePath);

                    if ($source === false || ! $disk->put($destinationPath, $source)) {
                        if (is_resource($source)) {
                            fclose($source);
                        }

                        throw BridgePackageException::receiveFailed($destinationPath);
                    }

                    fclose($source);
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
