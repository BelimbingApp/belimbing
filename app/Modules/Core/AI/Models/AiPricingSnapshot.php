<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Imported pricing snapshot for one provider/model.
 *
 * @property int $id
 * @property string|null $provider
 * @property string $model
 * @property string $input_cents_per_token
 * @property string|null $cached_input_cents_per_token
 * @property string $output_cents_per_token
 * @property string $source
 * @property string|null $source_version
 * @property Carbon $snapshot_date
 * @property array<string, mixed>|null $raw
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiPricingSnapshot extends Model
{
    protected $table = 'ai_pricing_snapshots';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'provider',
        'model',
        'input_cents_per_token',
        'cached_input_cents_per_token',
        'output_cents_per_token',
        'source',
        'source_version',
        'snapshot_date',
        'raw',
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
            'snapshot_date' => 'date',
            'raw' => 'json',
        ];
    }
}
