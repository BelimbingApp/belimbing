<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\DTO\Messaging\ChannelAccount as ChannelAccountDto;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Persistent channel account — company-scoped messaging credentials.
 *
 * Stores the configuration needed to send and receive messages through
 * a specific channel (email, WhatsApp, Telegram, Slack) on behalf of
 * a company.
 *
 * @property int $id
 * @property int $company_id
 * @property string $channel
 * @property string $label
 * @property string|null $credentials
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 * @property int|null $owner_employee_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Employee|null $ownerEmployee
 */
class ChannelAccount extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_channel_accounts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'channel',
        'label',
        'credentials',
        'is_enabled',
        'config',
        'owner_employee_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'is_enabled' => 'boolean',
            'config' => 'json',
        ];
    }

    /**
     * Get the company that owns this channel account.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the owner employee (agent) for this account.
     */
    public function ownerEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /**
     * Get conversations associated with this account.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'channel_account_id');
    }

    /**
     * Convert to the adapter-facing DTO.
     */
    public function toDto(): ChannelAccountDto
    {
        return new ChannelAccountDto(
            id: (string) $this->id,
            channelId: $this->channel,
            companyId: $this->company_id,
            credentials: $this->credentials ? json_decode($this->credentials, true) ?? [] : [],
            ownerEmployeeId: $this->owner_employee_id,
        );
    }
}
