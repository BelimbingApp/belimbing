<?php

namespace App\Modules\Commerce\Catalog\Livewire\Concerns;

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait ManagesCatalogTemplates
{
    public ?int $templateCategoryId = null;

    public string $templateName = '';

    public string $templateCode = '';

    public ?string $templateDescription = null;

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

    public function updatedTemplateName(string $value): void
    {
        if ($this->templateCode === '') {
            $this->templateCode = Str::slug($value);
        }
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

    public function toggleTemplateActive(int $templateId): void
    {
        $this->authorizeManage();
        $template = ProductTemplate::query()
            ->where('company_id', $this->companyId())
            ->findOrFail($templateId);
        $template->is_active = ! $template->is_active;
        $template->save();
    }
}
