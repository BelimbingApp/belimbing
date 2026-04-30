<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Operator-maintained pricing override for one provider/model.
 *
 * @property int $id
 * @property string|null $provider
 * @property string $model
 * @property string $input_cents_per_token
 * @property string|null $cached_input_cents_per_token
 * @property string $output_cents_per_token
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class AiPricingOverride extends Model
{
    protected $table = 'ai_pricing_overrides';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'provider',
        'model',
        'input_cents_per_token',
        'cached_input_cents_per_token',
        'output_cents_per_token',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_cents_per_token' => 'decimal:12',
            'cached_input_cents_per_token' => 'decimal:12',
            'output_cents_per_token' => 'decimal:12',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
