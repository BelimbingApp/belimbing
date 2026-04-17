<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;

interface ProviderRequestMapper
{
    /**
     * @return array<string, mixed>
     */
    public function mapPayload(ChatRequest $request, bool $stream): array;
}
