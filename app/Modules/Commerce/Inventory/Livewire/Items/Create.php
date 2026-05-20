<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Services\DefaultCurrencyResolver;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    public string $sku = '';

    public string $title = '';

    public ?string $notes = null;

    public string $status = Item::STATUS_DRAFT;

    public int $quantityOnHand = 1;

    public ?string $storageLocation = null;

    public ?string $unitCostAmount = null;

    public ?string $targetPriceAmount = null;

    public string $currencyCode = 'MYR';

    public ?int $categoryId = null;

    public ?int $productTemplateId = null;

    public function mount(DefaultCurrencyResolver $currencyResolver): void
    {
        $companyId = Auth::user()?->company_id;
        $this->currencyCode = $currencyResolver->forCompany(Auth::user()?->company_id);

        if ($companyId === null) {
            return;
        }

        $templateId = request()->integer('template_id') ?: null;
        if ($templateId === null) {
            return;
        }

        $template = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find($templateId);

        if ($template instanceof ProductTemplate) {
            $this->productTemplateId = $template->id;
            $this->categoryId = $template->category_id;
        }
    }

    public function store(InventoryItemService $items): void
    {
        $companyId = Auth::user()?->company_id;

        if ($companyId === null) {
            Session::flash('error', __('Your account must belong to a company before you can create inventory items.'));

            return;
        }

        $this->sku = strtoupper(trim($this->sku));

        $validated = $this->validate([
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Item::class, 'sku')->where('company_id', $companyId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'quantityOnHand' => ['required', 'integer', 'min:0', 'max:999999'],
            'storageLocation' => ['nullable', 'string', 'max:255'],
            'unitCostAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'targetPriceAmount' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/'],
            'currencyCode' => ['required', 'string', 'size:3'],
            'categoryId' => ['nullable', 'integer', Rule::exists(Category::class, 'id')->where('company_id', $companyId)],
            'productTemplateId' => ['nullable', 'integer', Rule::exists(ProductTemplate::class, 'id')->where('company_id', $companyId)],
        ]);

        $validated = $this->normalizeCatalogFit($validated, $companyId);

        $item = $items->create($companyId, $validated);

        Session::flash('success', __('Item created successfully.'));

        $this->redirect(route('commerce.inventory.items.show', $item), navigate: true);
    }

    public function updatedSku(): void
    {
        // Don't mutate on every keystroke (it breaks caret position in the input).
    }

    public function skuAvailability(): ?bool
    {
        $companyId = Auth::user()?->company_id;
        $sku = trim($this->sku);

        if ($companyId === null || $sku === '') {
            return null;
        }

        return ! Item::query()
            ->where('company_id', $companyId)
            ->where('sku', strtoupper($sku))
            ->exists();
    }

    public function updatedCategoryId(mixed $value): void
    {
        $this->categoryId = $value !== '' && $value !== null ? (int) $value : null;

        $template = $this->selectedProductTemplate();
        if ($template instanceof ProductTemplate
            && $template->category_id !== null
            && $template->category_id !== $this->categoryId) {
            $this->productTemplateId = null;
        }
    }

    public function updatedProductTemplateId(mixed $value): void
    {
        $this->productTemplateId = $value !== '' && $value !== null ? (int) $value : null;

        $template = $this->selectedProductTemplate();
        if ($template instanceof ProductTemplate && $template->category_id !== null) {
            $this->categoryId = $template->category_id;
        }
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.create', [
            'statuses' => Item::statuses(),
            'skuAvailable' => $this->skuAvailability(),
            'categories' => Category::query()
                ->where('company_id', Auth::user()?->company_id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productTemplates' => ProductTemplate::query()
                ->where('company_id', Auth::user()?->company_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeCatalogFit(array $validated, int $companyId): array
    {
        $categoryId = $validated['categoryId'] ?? null;
        $templateId = $validated['productTemplateId'] ?? null;

        if ($templateId === null) {
            return $validated;
        }

        $template = ProductTemplate::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->findOrFail($templateId);

        if ($template->category_id !== null && $categoryId !== null && (int) $categoryId !== $template->category_id) {
            throw ValidationException::withMessages([
                'productTemplateId' => __('The selected template belongs to a different category.'),
            ]);
        }

        $validated['categoryId'] = $categoryId ?? $template->category_id;
        $validated['productTemplateId'] = $template->id;

        return $validated;
    }

    private function selectedProductTemplate(): ?ProductTemplate
    {
        if ($this->productTemplateId === null) {
            return null;
        }

        return ProductTemplate::query()
            ->where('company_id', Auth::user()?->company_id)
            ->find($this->productTemplateId);
    }
}
