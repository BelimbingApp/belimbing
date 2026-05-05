<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Services;

use App\Base\Integration\Models\OutboundExchange;

final readonly class IntegrationResponse
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public ?int $status,
        public string $body,
        public array $headers,
        public ?OutboundExchange $exchange,
    ) {}

    public function successful(): bool
    {
        return $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->body, true);

        if (! is_array($decoded)) {
            return $key === null ? $default : data_get([], $key, $default);
        }

        return $key === null ? $decoded : data_get($decoded, $key, $default);
    }
}
