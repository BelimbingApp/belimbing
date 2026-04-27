<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Catalog\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $tab = 'attributes';

    public string $search = '';

    public string $filterCategoryId = '';

    public string $filterTemplateId = '';

    public string $filterType = '';

    public string $sortBy = 'sort_order';

    public string $sortDir = 'asc';

    public bool $showCreateModal = false;

    public string $categoryName = '';

    public string $categoryCode = '';

    public ?string $categoryDescription = null;

    public ?int $templateCategoryId = null;

    public string $templateName = '';

    public string $templateCode = '';

    public ?string $templateDescription = null;

    public ?int $attributeCategoryId = null;

    public ?int $attributeProductTemplateId = null;

    public string $attributeName = '';

    public string $attributeCode = '';

    public string $attributeType = Attribute::TYPE_TEXT;

    public bool $attributeRequired = false;

    public ?string $attributeOptions = null;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->tabs(), true)) {
            return;
        }

        $this->tab = $tab;
        $this->sortBy = $this->defaultSortBy($tab);
        $this->sortDir = $this->defaultSortDir($tab);
        $this->resetPage();
        $this->resetValidation();
    }

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: $this->sortableColumns(),
            defaultDir: $this->sortableDefaultDirections(),
        );
    }

    public function openCreateModal(?string $tab = null): void
    {
        if ($tab !== null) {
            $this->setTab($tab);
        }

        $this->showCreateModal = true;
    }

    public function updatedFilterCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTemplateId(): void
    {
        $this->resetPage();
    }

    public function updatedFilterType(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryName(string $value): void
    {
        if ($this->categoryCode === '') {
            $this->categoryCode = Str::slug($value);
        }
    }

    public function updatedTemplateName(string $value): void
    {
        if ($this->templateCode === '') {
            $this->templateCode = Str::slug($value);
        }
    }

    public function updatedAttributeName(string $value): void
    {
        if ($this->attributeCode === '') {
            $this->attributeCode = Str::slug($value, '_');
        }
    }

    public function createCategory(): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();

        $validated = $this->validate([
            'categoryName' => ['required', 'string', 'max:255'],
            'categoryCode' => ['required', 'string', 'max:255', Rule::unique(Category::class, 'code')->where('company_id', $companyId)],
            'categoryDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        Category::query()->create([
            'company_id' => $companyId,
            'code' => Str::slug($validated['categoryCode']),
            'name' => $validated['categoryName'],
            'description' => $validated['categoryDescription'] ?: null,
        ]);

        $this->reset('categoryName', 'categoryCode', 'categoryDescription');
        $this->showCreateModal = false;
        session()->flash('success', __('Category created.'));
    }

    public function createTemplate(): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();

        $validated = $this->validate([
            'templateCategoryId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'templateName' => ['required', 'string', 'max:255'],
            'templateCode' => ['required', 'string', 'max:255', Rule::unique(ProductTemplate::class, 'code')->where('company_id', $companyId)],
            'templateDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        ProductTemplate::query()->create([
            'company_id' => $companyId,
            'category_id' => $validated['templateCategoryId'] ?: null,
            'code' => Str::slug($validated['templateCode']),
            'name' => $validated['templateName'],
            'description' => $validated['templateDescription'] ?: null,
        ]);

        $this->reset('templateCategoryId', 'templateName', 'templateCode', 'templateDescription');
        $this->showCreateModal = false;
        session()->flash('success', __('Template created.'));
    }

    public function createAttribute(): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();

        $validated = $this->validate([
            'attributeCategoryId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'attributeProductTemplateId' => ['nullable', 'integer', Rule::exists(ProductTemplate::class, 'id')->where('company_id', $companyId)],
            'attributeName' => ['required', 'string', 'max:255'],
            'attributeCode' => ['required', 'string', 'max:255'],
            'attributeType' => ['required', Rule::in(Attribute::types())],
            'attributeRequired' => ['boolean'],
            'attributeOptions' => ['nullable', 'string', 'max:5000'],
        ]);

        Attribute::query()->create([
            'company_id' => $companyId,
            'category_id' => $validated['attributeCategoryId'] ?: null,
            'product_template_id' => $validated['attributeProductTemplateId'] ?: null,
            'code' => Str::slug($validated['attributeCode'], '_'),
            'name' => $validated['attributeName'],
            'type' => $validated['attributeType'],
            'is_required' => $validated['attributeRequired'],
            'options' => $this->parseAttributeOptions($validated['attributeOptions'] ?? null),
        ]);

        $this->reset(
            'attributeCategoryId',
            'attributeProductTemplateId',
            'attributeName',
            'attributeCode',
            'attributeType',
            'attributeRequired',
            'attributeOptions',
        );
        $this->attributeType = Attribute::TYPE_TEXT;
        $this->showCreateModal = false;
        session()->flash('success', __('Attribute created.'));
    }

    public function saveCategoryField(int $categoryId, string $field, mixed $value): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();
        $category = Category::query()
            ->where('company_id', $companyId)
            ->findOrFail($categoryId);

        if (! in_array($field, ['code', 'name', 'description', 'sort_order'], true)) {
            return;
        }

        $rules = [
            'code' => ['required', 'string', 'max:255', Rule::unique((new Category)->getTable(), 'code')->where('company_id', $companyId)->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        $category->{$field} = $field === 'code'
            ? Str::slug($validated[$field])
            : $validated[$field];
        $category->save();
    }

    public function saveTemplateField(int $templateId, string $field, mixed $value): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();
        $template = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->findOrFail($templateId);

        if (! in_array($field, ['category_id', 'code', 'name', 'description'], true)) {
            return;
        }

        $rules = [
            'category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'code' => ['required', 'string', 'max:255', Rule::unique((new ProductTemplate)->getTable(), 'code')->where('company_id', $companyId)->ignore($templateId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        $template->{$field} = match ($field) {
            'category_id' => $validated[$field] ?: null,
            'code' => Str::slug($validated[$field]),
            default => $validated[$field],
        };
        $template->save();
    }

    public function saveAttributeField(int $attributeId, string $field, mixed $value): void
    {
        $this->authorizeManage();
        $companyId = $this->companyId();
        $attribute = Attribute::query()
            ->where('company_id', $companyId)
            ->findOrFail($attributeId);

        if (! in_array($field, ['category_id', 'product_template_id', 'code', 'name', 'type', 'options', 'sort_order'], true)) {
            return;
        }

        $rules = [
            'category_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'product_template_id' => ['nullable', 'integer', Rule::exists(ProductTemplate::class, 'id')->where('company_id', $companyId)],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Attribute::types())],
            'options' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        $attribute->{$field} = match ($field) {
            'category_id', 'product_template_id' => $validated[$field] ?: null,
            'code' => Str::slug($validated[$field], '_'),
            'options' => $this->parseAttributeOptions($validated[$field] ?? null),
            default => $validated[$field],
        };
        $attribute->save();
    }

    public function toggleTemplateActive(int $templateId): void
    {
        $this->authorizeManage();
        $template = ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($templateId);
        $template->is_active = ! $template->is_active;
        $template->save();
    }

    public function toggleAttributeRequired(int $attributeId): void
    {
        $this->authorizeManage();
        $attribute = Attribute::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($attributeId);
        $attribute->is_required = ! $attribute->is_required;
        $attribute->save();
    }

    public function render(): View
    {
        $companyId = $this->companyId();
        $categories = Category::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $templates = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('livewire.commerce.catalog.index', [
            'categories' => $categories,
            'templates' => $templates,
            'rows' => $this->rows($companyId),
            'attributeTypes' => Attribute::types(),
            'sortableColumns' => $this->sortableColumns(),
            'tabs' => [
                ['id' => 'categories', 'label' => __('Categories'), 'icon' => 'heroicon-o-folder'],
                ['id' => 'templates', 'label' => __('Templates'), 'icon' => 'heroicon-o-clipboard-document-list'],
                ['id' => 'attributes', 'label' => __('Attributes'), 'icon' => 'heroicon-o-tag'],
            ],
        ]);
    }

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
            ->where($categoryTable.'.company_id', $companyId)
            ->withCount(['attributes', 'productTemplates'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($categoryTable): void {
                $query->where($categoryTable.'.code', 'like', '%'.$this->search.'%')
                    ->orWhere($categoryTable.'.name', 'like', '%'.$this->search.'%')
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
            ->where($templateTable.'.company_id', $companyId)
            ->with('category')
            ->withCount('attributes')
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($templateTable): void {
                $query->where($templateTable.'.code', 'like', '%'.$this->search.'%')
                    ->orWhere($templateTable.'.name', 'like', '%'.$this->search.'%')
                    ->orWhere($templateTable.'.description', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterCategoryId !== '', fn (Builder $query) => $query->where($templateTable.'.category_id', (int) $this->filterCategoryId));

        if ($this->sortBy === 'category_name') {
            $query->leftJoin($categoryTable.' as sort_categories', $templateTable.'.category_id', '=', 'sort_categories.id');
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
            ->where($attributeTable.'.company_id', $companyId)
            ->with(['category', 'productTemplate'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($attributeTable): void {
                $query->where($attributeTable.'.code', 'like', '%'.$this->search.'%')
                    ->orWhere($attributeTable.'.name', 'like', '%'.$this->search.'%');
            }))
            ->when($this->filterCategoryId !== '', fn (Builder $query) => $query->where($attributeTable.'.category_id', (int) $this->filterCategoryId))
            ->when($this->filterTemplateId !== '', fn (Builder $query) => $query->where($attributeTable.'.product_template_id', (int) $this->filterTemplateId))
            ->when($this->filterType !== '', fn (Builder $query) => $query->where($attributeTable.'.type', $this->filterType));

        if ($this->sortBy === 'category_name') {
            $query->leftJoin($categoryTable.' as sort_categories', $attributeTable.'.category_id', '=', 'sort_categories.id');
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
        $column = $this->sortableColumns()[$this->sortBy] ?? $templateTable.'.name';

        return $query
            ->orderBy($column, $this->sortDir)
            ->orderBy($templateTable.'.name');
    }

    /**
     * @param  Builder<Attribute>  $query
     * @return Builder<Attribute>
     */
    private function applyAttributeSort(Builder $query, string $attributeTable): Builder
    {
        $column = $this->sortableColumns()[$this->sortBy] ?? $attributeTable.'.sort_order';

        return $query
            ->orderBy($column, $this->sortDir)
            ->orderBy($attributeTable.'.name');
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
                'code' => $categoryTable.'.code',
                'name' => $categoryTable.'.name',
                'product_templates_count' => 'product_templates_count',
                'attributes_count' => 'attributes_count',
                'sort_order' => $categoryTable.'.sort_order',
            ],
            'templates' => [
                'code' => $templateTable.'.code',
                'name' => $templateTable.'.name',
                'category_name' => 'sort_categories.name',
                'is_active' => $templateTable.'.is_active',
                'attributes_count' => 'attributes_count',
            ],
            default => [
                'code' => $attributeTable.'.code',
                'name' => $attributeTable.'.name',
                'type' => $attributeTable.'.type',
                'category_name' => 'sort_categories.name',
                'template_name' => 'sort_templates.name',
                'is_required' => $attributeTable.'.is_required',
                'sort_order' => $attributeTable.'.sort_order',
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
