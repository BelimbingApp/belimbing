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
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemDescriptions;
use App\Modules\Commerce\Inventory\Livewire\Items\Concerns\ManagesItemFitments;
use App\Modules\Commerce\Inventory\Models\Item;
use App\Modules\Commerce\Inventory\Models\ItemPhoto;
use App\Modules\Commerce\Inventory\Services\InventoryItemService;
use App\Modules\Commerce\Marketplace\Models\Listing;
use App\Modules\Commerce\Marketplace\Models\ListingDraft;
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
    use ManagesItemDescriptions;
    use ManagesItemFitments;
    use SavesValidatedFields;
    use WithFileUploads;

    public Item $item;

    /**
     * @var array<int, mixed>
     */
    public array $photoFiles = [];

    /**
     * @var list<string>
     */
    public array $selectedChannels = [];

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item->load('category', 'productTemplate', 'photos', 'fitments', 'catalogAttributeValues.attribute', 'descriptions.createdByUser');
        $this->catalogCategoryId = $this->item->category_id;
        $this->catalogProductTemplateId = $this->item->product_template_id;
        $this->selectedChannels = array_keys(app(MarketplaceChannelRegistry::class)->all());
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

    public function refreshAllChannelReadiness(MarketplaceChannelRegistry $channels): void
    {
        $this->authorizeUpdate();

        $refreshed = 0;
        $failures = [];

        foreach ($channels->all() as $channel => $descriptor) {
            try {
                $channels->channel($channel)->refreshListingDraft($this->item->fresh() ?? $this->item);
                $refreshed++;
            } catch (Throwable $exception) {
                $failures[] = $descriptor->label.': '.$exception->getMessage();
            }
        }

        $this->item->refresh();

        if ($failures === []) {
            session()->flash('success', trans_choice('Refreshed :count channel readiness check.|Refreshed :count channel readiness checks.', $refreshed, ['count' => $refreshed]));

            return;
        }

        $message = implode(' ', array_slice($failures, 0, 2));

        if ($refreshed > 0) {
            session()->flash('warning', __('Refreshed :count channel(s), but some checks failed: :message', ['count' => $refreshed, 'message' => $message]));

            return;
        }

        session()->flash('error', $message);
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

    public function pushSelectedChannels(MarketplaceListingPushService $pushes): void
    {
        $this->authorizeMarketplacePush();

        $channels = $this->readyChannelKeys($this->normalizedSelectedChannels());

        if ($channels === []) {
            session()->flash('error', __('Select at least one ready channel before pushing.'));

            return;
        }

        $this->flashPushResult($pushes->push($this->item, $channels));
        $this->item->refresh();
    }

    public function pushAllReadyChannels(MarketplaceListingPushService $pushes): void
    {
        $this->authorizeMarketplacePush();

        $channels = $this->readyChannelKeys();

        if ($channels === []) {
            session()->flash('error', __('No registered channel is ready to push yet. Refresh readiness and resolve the blockers first.'));

            return;
        }

        $this->flashPushResult($pushes->push($this->item, $channels));
        $this->item->refresh();
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if ($field === 'sku') {
            $value = strtoupper(trim((string) $value));
        }

        if ($field === 'storage_location' && trim((string) $value) === '') {
            $value = null;
        }

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
            },
        );

        $this->item->refresh();
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
     * @param  list<string>|null  $selected
     * @return list<string>
     */
    private function readyChannelKeys(?array $selected = null): array
    {
        $selected = $selected ?? array_keys(app(MarketplaceChannelRegistry::class)->all());

        return collect($this->channelRows(app(MarketplaceChannelRegistry::class)))
            ->filter(fn (array $row): bool => $row['can_push'] && in_array($row['key'], $selected, true))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function normalizedSelectedChannels(): array
    {
        return collect($this->selectedChannels)
            ->map(fn (mixed $channel): string => trim((string) $channel))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
