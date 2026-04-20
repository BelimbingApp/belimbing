<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Contracts;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;

interface LlmTransportTap
{
    public function request(ChatRequest $request, ProviderRequestMapping $mapping, string $endpoint, bool $stream): void;

    public function responseStatus(int $statusCode, bool $stream): void;

    public function responseBody(string $body, int $statusCode): void;

    public function firstByte(): void;

    public function streamLine(string $line): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $stage, string $message, array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function complete(array $context = []): void;
}
