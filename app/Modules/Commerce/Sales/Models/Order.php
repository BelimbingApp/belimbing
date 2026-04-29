<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Sales\Models;

use App\Modules\Commerce\Sales\Database\Factories\OrderFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Channel-agnostic marketplace order.
 *
 * @property int $id
 * @property int $company_id
 * @property string $channel
 * @property string $external_order_id
 * @property string|null $marketplace_id
 * @property string|null $buyer_username
 * @property string|null $buyer_email
 * @property string|null $status
 * @property Carbon|null $ordered_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $fulfilled_at
 * @property int|null $total_amount
 * @property string|null $currency_code
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $raw_payload
 * @property-read Company $company
 * @property-read Collection<int, OrderLine> $lines
 * @property-read Collection<int, Sale> $sales
 */
class Order extends Model
{
    use HasFactory;

    protected $table = 'commerce_sales_orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'channel',
        'external_order_id',
        'marketplace_id',
        'buyer_username',
        'buyer_email',
        'status',
        'ordered_at',
        'paid_at',
        'fulfilled_at',
        'total_amount',
        'currency_code',
        'last_synced_at',
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function newFactory(): OrderFactory
    {
        return new OrderFactory;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'order_id');
    }
}
