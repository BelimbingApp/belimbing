<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait InteractsWithCatalogWorkbenchData
{
    private const COLUMN_CATEGORY_ID = '.category_id';

    private const COLUMN_CODE = '.code';

    private const COLUMN_COMPANY_ID = '.company_id';

    private const COLUMN_NAME = '.name';

    private const COLUMN_SORT_ORDER = '.sort_order';

    /**
     * @return LengthAwarePaginator<int, Category|ProductTemplate|Attribute>
     */
    private function rows(int $companyId): LengthAwarePaginator
    {
        return match ($this->tab) {
            'categories' => $this->categoryRows($companyId),
            'templates' => $this->templateRows($companyId),
            default => $this->attributeRows($companyId),
        };
    }

    /**
     * @return LengthAwarePaginator<int, Category>
     */
    private function categoryRows(int $companyId): LengthAwarePaginator
    {
        $categoryTable = (new Category)->getTable();

        $query = Category::query()
            ->where($categoryTable.self::COLUMN_COMPANY_ID, $companyId)
            ->withCount(['attributes', 'productTemplates'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($categoryTable): void {
                $query->where($categoryTable.self::COLUMN_CODE, 'like', '%'.$this->search.'%')
                    ->orWhere($categoryTable.self::COLUMN_NAME, 'like', '%'.$this->search.'%')
                    ->orWhere($categoryTable.'.description', 'like', '%'.$this->search.'%');
            }));

        return $this->applyCategorySort($query)->paginate(20);
    }

    /**
     * @return LengthAwarePaginator<int, ProductTemplate>
     */
    private function templateRows(int $companyId): LengthAwarePaginator
    {
        $templateTable = (new ProductTemplate)->getTable();
        $categoryTable = (new Category)->getTable();

        $query = ProductTemplate::query()
            ->select($templateTable.'.*')
            ->where($templateTable.self::COLUMN_COMPANY_ID, $companyId)
            ->with('category')
            ->withCount('attributes')
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($templateTable): void {
                $query->where($templateTable.self::COLUMN_CODE, 'like', '%'.$this->search.'%')
                    ->orWhere($templateTable.self::COLUMN_NAME, 'like', '%'.$this->search.'%')
                    ->orWhere($templateTable.'.description', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterCategoryId !== '', fn (Builder $query) => $query->where($templateTable.self::COLUMN_CATEGORY_ID, (int) $this->filterCategoryId));

        if ($this->sortBy === 'category_name') {
            $query->leftJoin($categoryTable.' as sort_categories', $templateTable.self::COLUMN_CATEGORY_ID, '=', 'sort_categories.id');
        }

        return $this->applyTemplateSort($query, $templateTable)->paginate(20);
    }

    /**
     * @return LengthAwarePaginator<int, Attribute>
     */
    private function attributeRows(int $companyId): LengthAwarePaginator
    {
        $attributeTable = (new Attribute)->getTable();
        $categoryTable = (new Category)->getTable();
        $templateTable = (new ProductTemplate)->getTable();

        $query = Attribute::query()
            ->select($attributeTable.'.*')
            ->where($attributeTable.self::COLUMN_COMPANY_ID, $companyId)
            ->with(['category', 'productTemplate'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($attributeTable): void {
                $query->where($attributeTable.self::COLUMN_CODE, 'like', '%'.$this->search.'%')
                    ->orWhere($attributeTable.self::COLUMN_NAME, 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterCategoryId !== '', fn (Builder $query) => $query->where($attributeTable.self::COLUMN_CATEGORY_ID, (int) $this->filterCategoryId))
            ->when($this->filterTemplateId !== '', fn (Builder $query) => $query->where($attributeTable.'.product_template_id', (int) $this->filterTemplateId))
            ->when($this->filterType !== '', fn (Builder $query) => $query->where($attributeTable.'.type', $this->filterType));

        if ($this->sortBy === 'category_name') {
            $query->leftJoin($categoryTable.' as sort_categories', $attributeTable.self::COLUMN_CATEGORY_ID, '=', 'sort_categories.id');
        }

        if ($this->sortBy === 'template_name') {
            $query->leftJoin($templateTable.' as sort_templates', $attributeTable.'.product_template_id', '=', 'sort_templates.id');
        }

        return $this->applyAttributeSort($query, $attributeTable)->paginate(20);
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    private function applyCategorySort(Builder $query): Builder
    {
        $column = $this->sortableColumns()[$this->sortBy] ?? 'sort_order';

        return $query
            ->orderBy($column, $this->sortDir)
            ->orderBy('name');
    }

    /**
     * @param  Builder<ProductTemplate>  $query
     * @return Builder<ProductTemplate>
     */
    private function applyTemplateSort(Builder $query, string $templateTable): Builder
    {
        $column = $this->sortableColumns()[$this->sortBy] ?? $templateTable.self::COLUMN_NAME;

        return $query
            ->orderBy($column, $this->sortDir)
            ->orderBy($templateTable.self::COLUMN_NAME);
    }

    /**
     * @param  Builder<Attribute>  $query
     * @return Builder<Attribute>
     */
    private function applyAttributeSort(Builder $query, string $attributeTable): Builder
    {
        $column = $this->sortableColumns()[$this->sortBy] ?? $attributeTable.self::COLUMN_SORT_ORDER;

        return $query
            ->orderBy($column, $this->sortDir)
            ->orderBy($attributeTable.self::COLUMN_NAME);
    }

    /**
     * @return array<string, string>
     */
    private function sortableColumns(): array
    {
        $categoryTable = (new Category)->getTable();
        $templateTable = (new ProductTemplate)->getTable();
        $attributeTable = (new Attribute)->getTable();

        return match ($this->tab) {
            'categories' => [
                'code' => $categoryTable.self::COLUMN_CODE,
                'name' => $categoryTable.self::COLUMN_NAME,
                'product_templates_count' => 'product_templates_count',
                'attributes_count' => 'attributes_count',
                'sort_order' => $categoryTable.self::COLUMN_SORT_ORDER,
            ],
            'templates' => [
                'code' => $templateTable.self::COLUMN_CODE,
                'name' => $templateTable.self::COLUMN_NAME,
                'category_name' => 'sort_categories.name',
                'is_active' => $templateTable.'.is_active',
                'attributes_count' => 'attributes_count',
            ],
            default => [
                'code' => $attributeTable.self::COLUMN_CODE,
                'name' => $attributeTable.self::COLUMN_NAME,
                'type' => $attributeTable.'.type',
                'category_name' => 'sort_categories.name',
                'template_name' => 'sort_templates.name',
                'is_required' => $attributeTable.'.is_required',
                'sort_order' => $attributeTable.self::COLUMN_SORT_ORDER,
            ],
        };
    }

    private function defaultSortBy(?string $tab = null): string
    {
        return match ($tab ?? $this->tab) {
            'templates' => 'name',
            default => 'sort_order',
        };
    }

    private function defaultSortDir(?string $tab = null, ?string $column = null): string
    {
        $column ??= $this->defaultSortBy($tab);

        return in_array($column, ['attributes_count', 'product_templates_count'], true) ? 'desc' : 'asc';
    }

    /**
     * @return array<string, string>
     */
    private function sortableDefaultDirections(): array
    {
        return [
            'attributes_count' => 'desc',
            'product_templates_count' => 'desc',
        ];
    }

    /**
     * @return list<string>
     */
    private function tabs(): array
    {
        return ['categories', 'templates', 'attributes'];
    }

    /**
     * @return array<int, string>|null
     */
    private function parseAttributeOptions(?string $options): ?array
    {
        if ($options === null || trim($options) === '') {
            return null;
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $options) ?: [])
            ->map(fn (string $option): string => trim($option))
            ->filter()
            ->values()
            ->all();
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.catalog.manage',
        );
    }

    private function companyId(): int
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            abort(403);
        }

        return $companyId;
    }
}
