<?php

namespace App\Modules\Commerce\Catalog\Livewire\Concerns;

use App\Modules\Commerce\Catalog\Models\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ManagesCatalogCategories
{
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

    public function updatedCategoryName(string $value): void
    {
        if ($this->categoryCode === '') {
            $this->categoryCode = Str::slug($value);
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
