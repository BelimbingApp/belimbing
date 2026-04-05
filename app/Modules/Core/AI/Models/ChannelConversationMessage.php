<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\MessageDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Individual message within a channel conversation.
 *
 * @property int $id
 * @property int $conversation_id
 * @property MessageDirection $direction
 * @property string|null $external_message_id
 * @property string|null $content
 * @property array<int, mixed>|null $media
 * @property array<string, mixed>|null $raw_payload
 * @property string $delivery_status
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChannelConversation $conversation
 */
class ChannelConversationMessage extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_channel_conversation_messages';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'direction',
        'external_message_id',
        'content',
        'media',
        'raw_payload',
        'delivery_status',
        'sent_at',
        'delivered_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'media' => 'json',
            'raw_payload' => 'json',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * Get the conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChannelConversation::class, 'conversation_id');
    }
}
