<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\MessageDirection;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Channel conversation — tracks message threads across external channels.
 *
 * @property int $id
 * @property int $company_id
 * @property string $channel
 * @property int|null $channel_account_id
 * @property string|null $external_id
 * @property array<int, string>|null $participants
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_outbound_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read ChannelAccount|null $channelAccount
 */
class ChannelConversation extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_channel_conversations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'channel',
        'channel_account_id',
        'external_id',
        'participants',
        'last_inbound_at',
        'last_outbound_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participants' => 'json',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns this conversation.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the channel account used for this conversation.
     */
    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class, 'channel_account_id');
    }

    /**
     * Get all messages in this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChannelConversationMessage::class, 'conversation_id');
    }

    /**
     * Record that an outbound or inbound activity occurred.
     */
    public function touchActivity(MessageDirection $direction): void
    {
        $field = $direction === MessageDirection::Inbound ? 'last_inbound_at' : 'last_outbound_at';
        $this->update([$field => now()]);
    }
}
