<?php

namespace App\Modules\Commerce\Catalog\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Commerce\Catalog\Livewire\Concerns\InteractsWithCatalogWorkbenchData;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithCatalogWorkbenchData;
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

    public string $createKind = '';

    public ?int $selectedCategoryId = null;

    /**
     * @var array<int, int>
     */
    public array $expandedCategoryIds = [];

    public bool $categoryExpansionInitialized = false;

    public string $categoryName = '';

    public string $categoryCode = '';

    public ?string $categoryDescription = null;

    public ?int $categoryParentId = null;

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

    public function mount(?string $tab = null): void
    {
        if ($tab !== null && in_array($tab, $this->tabs(), true)) {
            $this->tab = $tab;
            $this->sortBy = $this->defaultSortBy($tab);
            $this->sortDir = $this->defaultSortDir($tab);
        }
    }

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

        if ($this->tab === 'categories') {
            $this->reset('categoryParentId', 'categoryName', 'categoryCode', 'categoryDescription');
        }

        $this->createKind = $this->tab;
        $this->showCreateModal = true;
    }

    public function selectCategory(int $categoryId): void
    {
        $category = Category::query()
            ->where('company_id', $this->companyId())
            ->with('parent.parent.parent.parent.parent')
            ->findOrFail($categoryId);

        $this->selectedCategoryId = $category->id;
        $this->expandedCategoryIds = array_values(array_unique([
            ...$this->expandedCategoryIds,
            ...$this->categoryAncestorIds($category),
        ]));
    }

    public function toggleCategoryExpansion(int $categoryId): void
    {
        Category::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($categoryId);

        if (in_array($categoryId, $this->expandedCategoryIds, true)) {
            $this->expandedCategoryIds = array_values(array_diff($this->expandedCategoryIds, [$categoryId]));

            return;
        }

        $this->expandedCategoryIds[] = $categoryId;
    }

    public function toggleAllCategoryExpansion(): void
    {
        $branchIds = Category::query()
            ->where('company_id', $this->companyId())
            ->whereHas('children')
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        if ($branchIds === []) {
            return;
        }

        $expandedBranchIds = array_intersect($branchIds, $this->expandedCategoryIds);

        $this->expandedCategoryIds = count($expandedBranchIds) === count($branchIds)
            ? []
            : $branchIds;
    }

    public function addChildCategory(?int $parentCategoryId = null): void
    {
        $this->setTab('categories');
        $this->reset('categoryName', 'categoryCode', 'categoryDescription');
        $this->categoryParentId = $parentCategoryId;
        $this->createKind = 'categories';
        $this->showCreateModal = true;
    }

    public function addCategoryTemplate(int $categoryId): void
    {
        Category::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($categoryId);

        $this->reset('templateName', 'templateCode', 'templateDescription');
        $this->templateCategoryId = $categoryId;
        $this->createKind = 'templates';
        $this->showCreateModal = true;
    }

    public function addCategoryAttribute(int $categoryId): void
    {
        Category::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($categoryId);

        $this->reset('attributeProductTemplateId', 'attributeName', 'attributeCode', 'attributeOptions');
        $this->attributeCategoryId = $categoryId;
        $this->attributeType = Attribute::TYPE_TEXT;
        $this->attributeRequired = false;
        $this->createKind = 'attributes';
        $this->showCreateModal = true;
    }

    public function showCategoryTemplates(int $categoryId): void
    {
        Category::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($categoryId);

        $this->setTab('templates');
        $this->filterCategoryId = (string) $categoryId;
        $this->search = '';
    }

    public function showCategoryAttributes(int $categoryId): void
    {
        Category::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($categoryId);

        $this->setTab('attributes');
        $this->filterCategoryId = (string) $categoryId;
        $this->filterTemplateId = '';
        $this->filterType = '';
        $this->search = '';
    }

    public function manageTemplateAttributes(int $templateId): void
    {
        ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($templateId);

        $this->setTab('attributes');
        $this->filterTemplateId = (string) $templateId;
        $this->filterCategoryId = '';
        $this->filterType = '';
        $this->search = '';
    }

    public function addTemplateAttribute(int $templateId): void
    {
        $template = ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($templateId);

        $this->setTab('attributes');
        $this->attributeCategoryId = $template->category_id;
        $this->attributeProductTemplateId = $template->id;
        $this->createKind = 'attributes';
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
            'categoryParentId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'categoryName' => ['required', 'string', 'max:255'],
            'categoryCode' => ['required', 'string', 'max:255', Rule::unique(Category::class, 'code')->where('company_id', $companyId)],
            'categoryDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        $category = Category::query()->create([
            'company_id' => $companyId,
            'parent_id' => $validated['categoryParentId'] ?: null,
            'code' => Str::slug($validated['categoryCode']),
            'name' => $validated['categoryName'],
            'description' => $validated['categoryDescription'] ?: null,
        ]);

        $this->selectedCategoryId = $category->id;

        if ($category->parent_id !== null) {
            $this->expandedCategoryIds = array_values(array_unique([...$this->expandedCategoryIds, $category->parent_id]));
        }

        $this->reset('categoryParentId', 'categoryName', 'categoryCode', 'categoryDescription');
        $this->createKind = '';
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
        $this->createKind = '';
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
        $this->createKind = '';
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

        if (! in_array($field, ['parent_id', 'code', 'name', 'description', 'sort_order'], true)) {
            return;
        }

        $rules = [
            'parent_id' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'code' => ['required', 'string', 'max:255', Rule::unique((new Category)->getTable(), 'code')->where('company_id', $companyId)->ignore($categoryId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();

        if ($field === 'parent_id') {
            $parentId = $validated[$field] ? (int) $validated[$field] : null;

            if ($parentId === $category->id) {
                $this->addError('categoryParentId', __('A category cannot be its own parent.'));

                return;
            }

            $parent = $parentId === null
                ? null
                : Category::query()->where('company_id', $companyId)->with('parent.parent.parent.parent.parent')->findOrFail($parentId);

            if ($parent instanceof Category && $parent->isDescendantOf($category)) {
                $this->addError('categoryParentId', __('A category cannot be moved under one of its sub-categories.'));

                return;
            }
        }

        $category->{$field} = match ($field) {
            'parent_id' => $validated[$field] ? (int) $validated[$field] : null,
            'code' => Str::slug($validated[$field]),
            default => $validated[$field],
        };
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
            ->with('parent.parent.parent.parent.parent')
            ->withCount(['attributes', 'children', 'productTemplates'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $categoryBranchIds = $categories->where('children_count', '>', 0)->pluck('id')->all();

        if ($this->tab === 'categories' && ! $this->categoryExpansionInitialized) {
            $this->expandedCategoryIds = $categoryBranchIds;
            $this->categoryExpansionInitialized = true;
        }

        $selectedCategory = $this->selectedCategory($companyId, $categories);

        $templates = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('livewire.commerce.catalog.index', [
            'categories' => $categories,
            'categoryBranchIds' => $categoryBranchIds,
            'categoryTree' => $this->categoryTree($categories),
            'selectedCategory' => $selectedCategory,
            'templates' => $templates,
            'rows' => $this->rows($companyId),
            'attributeTypes' => Attribute::types(),
            'sortableColumns' => $this->sortableColumns(),
            'tabs' => [
                ['id' => 'categories', 'label' => __('Categories'), 'icon' => 'heroicon-o-folder', 'route' => route('commerce.catalog.categories')],
                ['id' => 'templates', 'label' => __('Templates'), 'icon' => 'heroicon-o-clipboard-document-list', 'route' => route('commerce.catalog.templates')],
                ['id' => 'attributes', 'label' => __('Attributes'), 'icon' => 'heroicon-o-tag', 'route' => route('commerce.catalog.attributes')],
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function categoryTree(\Illuminate\Database\Eloquent\Collection $categories): Collection
    {
        $search = Str::lower(trim($this->search));
        $childrenByParent = $categories->groupBy(fn (Category $category): int => $category->parent_id ?? 0);

        $build = function (?int $parentId) use (&$build, $childrenByParent, $search): Collection {
            return ($childrenByParent->get($parentId ?? 0) ?? collect())
                ->map(function (Category $category) use ($build, $search): ?Category {
                    $children = $build($category->id);
                    $matches = $search === '' || str_contains(Str::lower($category->name), $search)
                        || str_contains(Str::lower($category->code), $search)
                        || str_contains(Str::lower($category->description ?? ''), $search)
                        || str_contains(Str::lower($category->path_label), $search);

                    if (! $matches && $children->isEmpty()) {
                        return null;
                    }

                    $category->setRelation('treeChildren', $children);

                    return $category;
                })
                ->filter()
                ->values();
        };

        return $build(null);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Category>  $categories
     */
    private function selectedCategory(int $companyId, \Illuminate\Database\Eloquent\Collection $categories): ?Category
    {
        $selectedId = $this->selectedCategoryId ?? $categories->first()?->id;

        if ($selectedId === null) {
            return null;
        }

        return Category::query()
            ->where('company_id', $companyId)
            ->with([
                'attributes.productTemplate',
                'children',
                'parent.parent.parent.parent.parent',
                'productTemplates',
            ])
            ->withCount(['attributes', 'children', 'productTemplates'])
            ->find($selectedId);
    }

    /**
     * @return array<int, int>
     */
    private function categoryAncestorIds(Category $category): array
    {
        $ids = [];
        $parent = $category->parent;
        $visited = [];

        while ($parent instanceof Category) {
            if (in_array($parent->id, $visited, true)) {
                break;
            }

            $visited[] = $parent->id;
            $ids[] = $parent->id;
            $parent = $parent->parent;
        }

        return $ids;
    }
}
