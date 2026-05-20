<?php

namespace App\Modules\Commerce\Marketplace\Models;

use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Category-scoped mapping from a Belimbing catalog attribute to a marketplace aspect.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $catalog_attribute_id
 * @property string $channel
 * @property string $marketplace_id
 * @property string|null $category_tree_id
 * @property string $category_id
 * @property string $internal_attribute_code
 * @property string $ebay_aspect_name
 * @property string $value_normalization
 * @property array<int, string>|null $enum_values
 * @property string $requirement_status
 * @property string $mapping_confidence
 * @property bool $is_enabled
 * @property string|null $notes
 * @property-read Company $company
 * @property-read Attribute|null $catalogAttribute
 */
class AspectMapping extends Model
{
    public const CATEGORY_ALL = '*';

    public const NORMALIZATION_COPY = 'copy';

    public const NORMALIZATION_TEXT = 'text';

    public const NORMALIZATION_NUMBER = 'number';

    public const NORMALIZATION_BOOLEAN = 'boolean';

    public const REQUIREMENT_UNKNOWN = 'unknown';

    public const REQUIREMENT_REQUIRED = 'required';

    public const REQUIREMENT_RECOMMENDED = 'recommended';

    public const REQUIREMENT_OPTIONAL = 'optional';

    public const CONFIDENCE_MANUAL = 'manual';

    public const CONFIDENCE_IMPORTED = 'imported';

    public const CONFIDENCE_SUGGESTED = 'suggested';

    protected $table = 'commerce_marketplace_aspect_mappings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'catalog_attribute_id',
        'channel',
        'marketplace_id',
        'category_tree_id',
        'category_id',
        'internal_attribute_code',
        'ebay_aspect_name',
        'value_normalization',
        'enum_values',
        'requirement_status',
        'mapping_confidence',
        'is_enabled',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enum_values' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function catalogAttribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'catalog_attribute_id');
    }

    /**
     * @param  Builder<AspectMapping>  $query
     * @return Builder<AspectMapping>
     */
    public function scopeForCategory(Builder $query, int $companyId, string $channel, string $marketplaceId, string $categoryId, ?string $categoryTreeId = null): Builder
    {
        return $query
            ->where('company_id', $companyId)
            ->where('channel', $channel)
            ->where('marketplace_id', $marketplaceId)
            ->when($categoryTreeId !== null, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($categoryTreeId): void {
                $query->whereNull('category_tree_id')
                    ->orWhere('category_tree_id', $categoryTreeId);
            }))
            ->whereIn('category_id', [self::CATEGORY_ALL, $categoryId])
            ->where('is_enabled', true)
            ->orderByRaw('case when category_id = ? then 0 else 1 end', [$categoryId])
            ->orderBy('ebay_aspect_name');
    }
}
