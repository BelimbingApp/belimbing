<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Durable audit record for one outbound external-system exchange.
 *
 * @property string $id
 * @property string $system
 * @property string|null $provider
 * @property string $operation
 * @property string $transport
 * @property string $protocol
 * @property string|null $protocol_operation
 * @property string $endpoint
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string|null $correlation_id
 * @property int|null $response_status
 * @property string $outcome
 * @property bool $fallback_used
 * @property string|null $fallback_reason
 * @property Carbon $occurred_at
 */
class OutboundExchange extends Model
{
    public const ID_PREFIX = 'ix_';

    protected $table = 'base_integration_outbound_exchanges';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'system',
        'provider',
        'operation',
        'transport',
        'protocol',
        'protocol_operation',
        'endpoint',
        'owner_type',
        'owner_id',
        'correlation_id',
        'traceparent',
        'tracestate',
        'request_headers',
        'request_body',
        'request_body_truncated',
        'request_body_original_bytes',
        'response_status',
        'response_headers',
        'response_body',
        'response_body_truncated',
        'response_body_original_bytes',
        'duration_ms',
        'retry_count',
        'outcome',
        'error_class',
        'error_message',
        'fallback_used',
        'fallback_reason',
        'metadata',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $exchange): void {
            if ($exchange->id === null || $exchange->id === '') {
                $exchange->id = self::ID_PREFIX.Str::ulid()->toBase32();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_body' => 'array',
            'request_body_truncated' => 'boolean',
            'request_body_original_bytes' => 'integer',
            'response_headers' => 'array',
            'response_body' => 'array',
            'response_body_truncated' => 'boolean',
            'response_body_original_bytes' => 'integer',
            'duration_ms' => 'integer',
            'retry_count' => 'integer',
            'fallback_used' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
