<?php

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Marketplace\Ebay\DTO\EbayBusinessPolicy;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A seller-account resource imported from a marketplace account.
 *
 * Examples: eBay payment/return/fulfillment policies and merchant locations.
 * These are setup choices for listing drafts, not marketplace metadata.
 *
 * @property int $id
 * @property int $company_id
 * @property string $channel
 * @property string $marketplace_id
 * @property string $kind
 * @property string $external_id
 * @property string $name
 * @property string|null $status
 * @property array<string, mixed>|null $payload
 * @property Carbon $imported_at
 * @property-read Company $company
 */
class AccountResource extends Model
{
    public const KIND_PAYMENT_POLICY = EbayBusinessPolicy::KIND_PAYMENT;

    public const KIND_FULFILLMENT_POLICY = EbayBusinessPolicy::KIND_FULFILLMENT;

    public const KIND_RETURN_POLICY = EbayBusinessPolicy::KIND_RETURN;

    public const KIND_INVENTORY_LOCATION = 'inventory_location';

    protected $table = 'commerce_marketplace_account_resources';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'channel',
        'marketplace_id',
        'kind',
        'external_id',
        'name',
        'status',
        'payload',
        'imported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isEnabled(): bool
    {
        return $this->status === null || $this->status === 'ENABLED';
    }

    /**
     * @param  Builder<AccountResource>  $query
     * @return Builder<AccountResource>
     */
    public function scopeForCompanyChannel(Builder $query, int $companyId, string $channel, string $marketplaceId): Builder
    {
        return $query
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->where('marketplace_id', $marketplaceId);
    }
}
