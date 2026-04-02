<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\SignalAuthenticityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inbound signal — durable intake record for inbound webhook/channel events.
 *
 * Captures raw and normalized forms of inbound traffic before routing
 * decisions are made, ensuring auditability and replay capability.
 *
 * @property int $id
 * @property string $channel
 * @property int|null $channel_account_id
 * @property SignalAuthenticityStatus $authenticity_status
 * @property string|null $sender_identifier
 * @property string|null $conversation_identifier
 * @property string|null $normalized_content
 * @property array<string, mixed>|null $normalized_payload
 * @property array<string, mixed>|null $raw_payload
 * @property int|null $resulting_operation_id
 * @property Carbon $received_at
 * @property Carbon|null $routed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChannelAccount|null $channelAccount
 */
class InboundSignal extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_inbound_signals';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'channel',
        'channel_account_id',
        'authenticity_status',
        'sender_identifier',
        'conversation_identifier',
        'normalized_content',
        'normalized_payload',
        'raw_payload',
        'resulting_operation_id',
        'received_at',
        'routed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authenticity_status' => SignalAuthenticityStatus::class,
            'normalized_payload' => 'json',
            'raw_payload' => 'json',
            'received_at' => 'datetime',
            'routed_at' => 'datetime',
        ];
    }

    /**
     * Get the channel account this signal was received on.
     */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    /**
     * Mark the signal as routed, recording the resulting operation.
     */
    public function markRouted(?string $operationId = null): void
    {
        $this->update([
            'resulting_operation_id' => $operationId,
            'routed_at' => now(),
        ]);
    }
}
