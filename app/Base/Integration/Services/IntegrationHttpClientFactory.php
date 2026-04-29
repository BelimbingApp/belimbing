<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class IntegrationHttpClientFactory
{
    public function json(string $baseUrl, ?string $bearerToken = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(3, 250);

        if ($bearerToken !== null && $bearerToken !== '') {
            $request = $request->withToken($bearerToken);
        }

        return $request;
    }
}
