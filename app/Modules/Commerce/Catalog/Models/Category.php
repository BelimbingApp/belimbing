<?php

namespace App\Modules\Commerce\Catalog\Models;

use App\Modules\Commerce\Catalog\Database\Factories\CategoryFactory;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;

/**
 * @property int $id
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property-read Company $company
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, ProductTemplate> $productTemplates
 * @property-read Collection<int, Attribute> $attributes
 * @property-read string $path_label
 */
class Category extends Model
{
    use HasFactory;

    protected $table = 'commerce_catalog_categories';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'description',
        'sort_order',
    ];

    protected static function newFactory(): CategoryFactory
    {
        return new CategoryFactory;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function pathLabel(): string
    {
        return $this->ancestorsAndSelf()
            ->pluck('name')
            ->implode(' › ');
    }

    public function getPathLabelAttribute(): string
    {
        return $this->pathLabel();
    }

    public function depth(): int
    {
        return max(0, $this->ancestorsAndSelf()->count() - 1);
    }

    public function isDescendantOf(self $category): bool
    {
        $parent = $this->parent;
        $visited = [];

        while ($parent instanceof self) {
            if (in_array($parent->id, $visited, true)) {
                return false;
            }

            $visited[] = $parent->id;

            if ($parent->is($category)) {
                return true;
            }

            $parent = $parent->parent;
        }

        return false;
    }

    /**
     * @return SupportCollection<int, Category>
     */
    private function ancestorsAndSelf(): SupportCollection
    {
        $categories = collect();
        $category = $this;
        $visited = [];

        while ($category instanceof self) {
            if (in_array($category->id, $visited, true)) {
                break;
            }

            $visited[] = $category->id;
            $categories->prepend($category);
            $category = $category->parent;
        }

        return $categories->values();
    }

    /**
     * @return HasMany<ProductTemplate, $this>
     */
    public function productTemplates(): HasMany
    {
        return $this->hasMany(ProductTemplate::class, 'category_id')->orderBy('name');
    }

    /**
     * @return HasMany<Attribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'category_id')->orderBy('sort_order')->orderBy('name');
    }
}
