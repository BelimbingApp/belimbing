<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Non-transition domain event for the quality module.
 *
 * Records facts that are not workflow transitions: evidence ingested,
 * AI artifact accepted, knowledge entry published.
 *
 * @property int $id
 * @property int|null $ncr_id
 * @property int|null $capa_id
 * @property int|null $scar_id
 * @property string $event_type
 * @property int|null $actor_id
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $occurred_at
 * @property Carbon|null $created_at
 * @property-read Ncr|null $ncr
 * @property-read Capa|null $capa
 * @property-read Scar|null $scar
 */
class QualityEvent extends Model
{
    /**
     * Indicates that the model does not have an "updated_at" column.
     *
     * @var string|null
     */
    const UPDATED_AT = null;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'quality_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'ncr_id',
        'capa_id',
        'scar_id',
        'event_type',
        'actor_id',
        'payload',
        'occurred_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'json',
            'occurred_at' => 'datetime',
            'actor_id' => 'integer',
        ];
    }

    /**
     * Get the NCR associated with this event.
     */
    public function ncr(): BelongsTo
    {
        return $this->belongsTo(Ncr::class);
    }

    /**
     * Get the CAPA associated with this event.
     */
    public function capa(): BelongsTo
    {
        return $this->belongsTo(Capa::class);
    }

    /**
     * Get the SCAR associated with this event.
     */
    public function scar(): BelongsTo
    {
        return $this->belongsTo(Scar::class);
    }
}
