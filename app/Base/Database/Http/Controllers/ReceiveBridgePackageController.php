<?php

namespace App\Base\Database\Http\Controllers;

use App\Base\Database\Exceptions\BridgePackageException;
use App\Base\Database\Exceptions\BridgePolicyException;
use App\Base\Database\Exceptions\BridgeTransportException;
use App\Base\Database\Services\Bridge\BridgePackageInbox;
use App\Base\Database\Services\Bridge\BridgePrivateStorage;
use App\Base\Database\Services\Bridge\BridgeReceiveGrantManager;
use App\Base\Database\Services\Bridge\BridgeUploadStager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiveBridgePackageController
{
    public function __invoke(
        Request $request,
        string $grantId,
        BridgeReceiveGrantManager $grants,
        BridgeUploadStager $uploads,
        BridgePrivateStorage $storage,
        BridgePackageInbox $inbox,
    ): JsonResponse {
        try {
            $grant = $grants->authenticate($grantId, (string) $request->bearerToken());
            $contentLength = filter_var(
                $request->header('Content-Length'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => $grant->max_bytes]],
            );

            if ($contentLength === false) {
                throw BridgeTransportException::invalidUpload();
            }

            $input = $request->getContent(true);
            $upload = $uploads->stage($input, 'https', $contentLength, $grant->max_bytes);
        } catch (BridgeTransportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $disk = $storage->disk();

        try {
            $receipt = $inbox->receiveFromProtectedPath(
                $upload->path,
                $grant,
            );
        } catch (BridgeTransportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        } catch (BridgePackageException|BridgePolicyException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            $disk->delete($upload->path);
        }

        return response()->json([
            'package_id' => $receipt->package_id,
            'sha256' => $receipt->package_sha256,
            'status' => $receipt->status,
            'grant_id' => $grant->grant_id,
        ], 202);
    }
}
