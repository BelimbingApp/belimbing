<?php

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Base\Foundation\ValueObjects\Money;
use App\Modules\Commerce\Catalog\Models\Category;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemAttributes;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemCatalogFit;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemFitments;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use App\Modules\Commerce\Marketplace\Ebay\EbayConfiguration;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
use App\Modules\Commerce\Marketplace\Services\MarketplaceAvailabilitySyncService;
use App\Modules\Commerce\Marketplace\Services\MarketplaceChannelRegistry;
use App\Modules\Commerce\Marketplace\Services\MarketplaceListingPushService;
use App\Modules\Commerce\Plugins\Services\CommercePluginRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Show extends Component
{
    use ManagesItemAttributes;
    use ManagesItemCatalogFit;
    use ManagesItemFitments;
    use SavesValidatedFields;
    use WithFileUploads;

    public Item $item;

    /**
     * @var array<int, mixed>
     */
    public array $photoFiles = [];

    public ?string $currencyCode = '';

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load('category', 'productTemplate', 'photos', 'fitments', 'catalogAttributeValues.attribute');
        $this->catalogCategoryId = $this->item->category_id;
        $this->catalogProductTemplateId = $this->item->product_template_id;
        $this->currencyCode = (string) $this->item->currency_code;
    }

    /**
     * Currency uses the shared GeoNames-backed combobox (same as the create form),
     * so it saves on selection rather than through the edit-in-place affordance.
     */
    public function updatedCurrencyCode(?string $value): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $this->authorizeUpdate();

        $validated = validator(
            ['currency_code' => strtoupper(trim($value))],
            ['currency_code' => ['required', 'string', 'size:3']],
        )->validate();

        $this->item->update(['currency_code' => $validated['currency_code']]);
        $this->item->refresh();
        $this->currencyCode = (string) $this->item->currency_code;
    }

    public function refreshChannelReadiness(string $channel, MarketplaceChannelRegistry $channels): void
    {
        $this->authorizeUpdate();

        $descriptor = $channels->descriptor($channel);

        try {
            $channels->channel($channel)->refreshListingDraft($this->item->fresh() ?? $this->item);
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->item->refresh();
        session()->flash('success', __(':channel readiness refreshed.', ['channel' => $descriptor->label]));
    }

    public function pushChannel(string $channel, MarketplaceListingPushService $pushes): void
    {
        $this->authorizeMarketplacePush();

        $rows = $this->channelRows(app(MarketplaceChannelRegistry::class));
        $target = collect($rows)
            ->first(fn (array $row): bool => $row['key'] === $channel && $row['can_push']);

        if (! $target) {
            session()->flash('error', __('This channel is not ready to push yet. Refresh readiness and resolve the blockers first.'));

            return;
        }

        $this->flashPushResult($pushes->push($this->item, [$channel]));
        $this->item->refresh();
    }

    public function saveField(string $field, mixed $value, MarketplaceAvailabilitySyncService $availability): void
    {
        $this->authorizeUpdate();

        if ($field === 'sku') {
            $value = strtoupper(trim((string) $value));
        }

        if ($field === 'storage_location' && trim((string) $value) === '') {
            $value = null;
        }

        $previousQuantity = (int) $this->item->quantity_on_hand;

        $this->saveValidatedField(
            $this->item,
            $field,
            $value,
            $this->fieldRules(),
            function ($model, string $field, mixed $validatedValue): void {
                if ($field === 'currency_code') {
                    $model->currency_code = strtoupper($validatedValue);
                }

                if ($field === 'sku') {
                    $model->sku = strtoupper($validatedValue);
                }

                if ($field === 'notes' && trim((string) $validatedValue) === '') {
                    $model->notes = null;
                }

                if ($field === 'description' && trim((string) $validatedValue) === '') {
                    $model->description = null;
                }
            },
        );

        $this->item->refresh();

        // Inventory is the source of truth for availability: a quantity change
        // (including a sale that drops it to 0) propagates to every channel
        // listing so the same stock cannot be sold twice.
        if ($field === 'quantity_on_hand' && (int) $this->item->quantity_on_hand !== $previousQuantity) {
            $this->flashAvailabilityResult($availability->syncItem($this->item));
        }
    }

    /**
     * @param  array{available: int, ended: list<array<string, mixed>>, revised: list<array<string, mixed>>, skipped: list<array<string, mixed>>, failures: list<array{label: string, message: string}>}  $result
     */
    private function flashAvailabilityResult(array $result): void
    {
        $touched = count($result['ended']) + count($result['revised']);
        $failureCount = count($result['failures']);
        $skippedCount = count($result['skipped']);

        if ($touched === 0 && $failureCount === 0 && $skippedCount === 0) {
            return;
        }

        $skippedNote = $skippedCount > 0
            ? ' '.trans_choice(':count listing needs attention to avoid overselling.|:count listings need attention to avoid overselling.', $skippedCount, ['count' => $skippedCount])
            : '';

        if ($failureCount > 0) {
            $message = collect($result['failures'])
                ->map(fn (array $failure): string => $failure['label'].': '.$failure['message'])
                ->take(2)
                ->implode(' ');

            session()->flash('warning', __('Availability sync failed on :count channel(s): :message', ['count' => $failureCount, 'message' => $message]).$skippedNote);

            return;
        }

        if ($touched === 0) {
            session()->flash('warning', trim($skippedNote));

            return;
        }

        if ($result['available'] === 0) {
            session()->flash('success', trans_choice('Out of stock — ended the listing on :count channel.|Out of stock — ended the listing on :count channels.', count($result['ended']), ['count' => count($result['ended'])]).$skippedNote);

            return;
        }

        session()->flash('success', trans_choice('Synced availability to :count channel.|Synced availability to :count channels.', $touched, ['count' => $touched]).$skippedNote);
    }

    public function uploadPhotos(InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $this->validate([
            'photoFiles' => ['required', 'array', 'min:1', 'max:12'],
            'photoFiles.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        DB::transaction(function () use ($items): void {
            $maxSort = (int) $this->item->photos()->max('sort_order');
            $sort = $maxSort;

            foreach ($this->photoFiles as $file) {
                if (! $file) {
                    continue;
                }

                $sort++;
                $items->uploadPhoto($this->item, $file, $sort);
            }
        });

        $this->photoFiles = [];
        $this->item->load('photos');
    }

    public function deletePhoto(int $photoId, InventoryItemService $items): void
    {
        $this->authorizeUpdate();

        $photo = $this->item->photos->firstWhere('id', $photoId);
        if (! $photo instanceof ItemPhoto) {
            return;
        }

        $items->deletePhoto($photo);

        $this->item->load('photos');
    }

    public function saveMoneyField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if (! in_array($field, ['unit_cost_amount', 'target_price_amount'], true)) {
            return;
        }

        $validated = validator(
            [$field => $value],
            [$field => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']],
        )->validate();

        $this->item->update([
            $field => Money::fromDecimalString($validated[$field] ?? null, $this->item->currency_code)?->minorAmount,
        ]);
        $this->item->refresh();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldRules(): array
    {
        return [
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Item::class, 'sku')
                    ->where('company_id', $this->item->company_id)
                    ->ignore($this->item),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'quantity_on_hand' => ['required', 'integer', 'min:0', 'max:999999'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'currency_code' => ['required', 'string', 'size:3'],
        ];
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, string $currencyCode): string
    {
        return Money::format($amount, $currencyCode);
    }

    public function canEdit(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'commerce.inventory.item.update')
            ->allowed;
    }

    public function canPushToMarketplace(): bool
    {
        $actor = Actor::forUser(Auth::user());
        $authorization = app(AuthorizationService::class);

        return $authorization->can($actor, 'commerce.inventory.item.update')->allowed
            && $authorization->can($actor, 'commerce.marketplace.execute')->allowed;
    }

    public function listingStatusVariant(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE', 'PUBLISHED' => 'success',
            'UNPUBLISHED', 'ENDED' => 'default',
            'INACTIVE' => 'warning',
            default => 'default',
        };
    }

    /**
     * The eBay category this item inherits from its product template, if the
     * template has been mapped (item metadata first, then a plugin default).
     * Drives the Catalog Fit card hint so the mapping is discoverable where the
     * template is chosen, not only behind a settings-page link.
     *
     * @return array{category_id: string, category_tree_id: string|null}|null
     */
    public function ebayCategoryMapping(): ?array
    {
        $template = $this->item->productTemplate;

        if ($template === null) {
            return null;
        }

        $metadata = $template->metadata ?? [];
        $categoryId = data_get($metadata, 'marketplace.ebay.category_id');
        $categoryTreeId = data_get($metadata, 'marketplace.ebay.category_tree_id');

        if (! is_string($categoryId) || trim($categoryId) === '') {
            $pluginMapping = app(CommercePluginRegistry::class)
                ->marketplaceTemplateMappingForTemplate(EbayConfiguration::CHANNEL, $template);
            $categoryId = $pluginMapping['category_id'] ?? null;
            $categoryTreeId = $pluginMapping['category_tree_id'] ?? $categoryTreeId;
        }

        if (! is_string($categoryId) || trim($categoryId) === '') {
            return null;
        }

        return [
            'category_id' => trim($categoryId),
            'category_tree_id' => is_string($categoryTreeId) && trim($categoryTreeId) !== '' ? trim($categoryTreeId) : null,
        ];
    }

    public function ebayCategorySettingsUrl(): ?string
    {
        return Route::has('commerce.marketplace.ebay.settings')
            ? route('commerce.marketplace.ebay.settings').'#categories'
            : null;
    }

    public function render(): View
    {
        return view('commerce-inventory::livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
            'availableAttributes' => $this->applicableAttributeQuery(Auth::user()?->company_id)->get(),
            'categories' => Category::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with('parent.parent.parent.parent.parent')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'productTemplates' => ProductTemplate::query()
                ->where('company_id', Auth::user()?->company_id)
                ->with('category.parent.parent.parent.parent.parent')
                ->orderBy('name')
                ->get(),
            'fitmentSourceItems' => Item::query()
                ->where('company_id', Auth::user()?->company_id)
                ->whereKeyNot($this->item->id)
                ->whereHas('fitments')
                ->withCount('fitments')
                ->orderBy('sku')
                ->limit(100)
                ->get(),
            'canBootstrapFitmentFromAttributes' => $this->fitmentAttributeCodes() !== [],
            'channelRows' => $this->channelRows(app(MarketplaceChannelRegistry::class)),
            'extensionReadinessPanels' => app(CommercePluginRegistry::class)->itemReadinessPanels($this->item),
        ]);
    }

    public function formatMoneyInput(?int $amount): ?string
    {
        return Money::formatInput($amount);
    }

    private function authorizeUpdate(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.inventory.item.update',
        );
    }

    private function authorizeMarketplacePush(): void
    {
        $this->authorizeUpdate();

        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'commerce.marketplace.execute',
        );
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     icon: string|null,
     *     listing: Listing|null,
     *     draft: ListingDraft|null,
     *     listed: bool,
     *     can_push: bool,
     *     supports_push: bool,
     *     readiness_status: string,
     *     readiness_variant: string,
     *     blockers: list<array<string, mixed>>,
     *     warnings: list<array<string, mixed>>,
     *     price_amount: int|null,
     *     currency_code: string,
     *     environment: string|null,
     *     requires_confirmation: bool,
     *     settings_url: string|null,
     *     index_url: string|null
     * }>
     */
    private function channelRows(MarketplaceChannelRegistry $channels): array
    {
        $descriptors = $channels->all();
        $keys = array_keys($descriptors);

        if ($keys === []) {
            return [];
        }

        $listings = Listing::query()
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->whereIn('channel', $keys)
            ->latest('updated_at')
            ->get()
            ->groupBy('channel');

        $drafts = ListingDraft::query()
            ->where('company_id', $this->item->company_id)
            ->where('item_id', $this->item->id)
            ->whereIn('channel', $keys)
            ->latest('updated_at')
            ->get()
            ->groupBy('channel');

        return collect($descriptors)
            ->map(function ($descriptor, string $channel) use ($listings, $drafts): array {
                $listing = $listings->get($channel)?->first();
                $draft = $drafts->get($channel)?->first();
                $readinessStatus = $draft?->readiness_status ?? 'unchecked';
                $listed = $listing instanceof Listing
                    && $listing->ended_at === null
                    && ! in_array(strtoupper((string) $listing->status), ['ENDED', 'UNPUBLISHED'], true);
                $operation = $listed ? 'revise_listing' : 'create_listing';
                $supportsPush = $descriptor->supports($operation);
                $blockers = collect(data_get($draft?->readiness_snapshot, 'blockers', []))
                    ->filter(fn (mixed $gap): bool => is_array($gap))
                    ->values()
                    ->all();
                $warnings = collect(data_get($draft?->readiness_snapshot, 'warnings', []))
                    ->filter(fn (mixed $gap): bool => is_array($gap))
                    ->values()
                    ->all();
                $environment = data_get($draft?->readiness_snapshot, 'facts.environment');

                return [
                    'key' => $channel,
                    'label' => $descriptor->label,
                    'icon' => $descriptor->icon,
                    'listing' => $listing instanceof Listing ? $listing : null,
                    'draft' => $draft instanceof ListingDraft ? $draft : null,
                    'listed' => $listed,
                    'can_push' => $supportsPush && $readinessStatus === 'ready',
                    'supports_push' => $supportsPush,
                    'readiness_status' => $readinessStatus,
                    'readiness_variant' => $this->readinessVariant($readinessStatus),
                    'blockers' => $blockers,
                    'warnings' => $warnings,
                    'price_amount' => $listing?->price_amount ?? $this->item->target_price_amount,
                    'currency_code' => $listing?->currency_code ?? $this->item->currency_code,
                    'environment' => is_string($environment) && trim($environment) !== '' ? trim($environment) : null,
                    'requires_confirmation' => $environment === 'live',
                    'settings_url' => $this->routeUrl($descriptor->routes['settings'] ?? null),
                    'index_url' => $this->routeUrl($descriptor->routes['index'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function readinessVariant(string $status): string
    {
        return match ($status) {
            'ready' => 'success',
            'blocked' => 'warning',
            default => 'default',
        };
    }

    private function routeUrl(mixed $routeName): ?string
    {
        return is_string($routeName) && Route::has($routeName) ? route($routeName) : null;
    }

    /**
     * @param  array{results: list<array{channel: string, label: string, operation: string, payload: array<string, mixed>}>, failures: list<array{channel: string, label: string, message: string}>}  $result
     */
    private function flashPushResult(array $result): void
    {
        $successCount = count($result['results']);
        $failureCount = count($result['failures']);
        $failureSummary = collect($result['failures'])
            ->map(fn (array $failure): string => $failure['label'].': '.$failure['message'])
            ->take(2)
            ->implode(' ');

        if ($failureCount === 0) {
            session()->flash('success', trans_choice('Pushed :count marketplace channel.|Pushed :count marketplace channels.', $successCount, ['count' => $successCount]));

            return;
        }

        if ($successCount > 0) {
            session()->flash('warning', __('Pushed :success channel(s), but :failed failed: :message', [
                'success' => $successCount,
                'failed' => $failureCount,
                'message' => $failureSummary,
            ]));

            return;
        }

        session()->flash('error', __('Nothing was pushed: :message', ['message' => $failureSummary]));
    }
}
