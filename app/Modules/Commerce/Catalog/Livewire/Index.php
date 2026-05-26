<?php

namespace App\Modules\Commerce\Catalog\Livewire;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Modules\Commerce\Catalog\Livewire\Concerns\InteractsWithCatalogWorkbenchData;
use App\Modules\Commerce\Catalog\Livewire\Concerns\ManagesCatalogCategories;
use App\Modules\Commerce\Catalog\Livewire\Concerns\ManagesCatalogTemplates;
use App\Modules\Commerce\Catalog\Models\Attribute;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithCatalogWorkbenchData;
    use ManagesCatalogCategories;
    use ManagesCatalogTemplates;
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

    public function updatedAttributeName(string $value): void
    {
        if ($this->attributeCode === '') {
            $this->attributeCode = Str::slug($value, '_');
        }
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

        return view('commerce-catalog::livewire.commerce.catalog.index', [
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
}
