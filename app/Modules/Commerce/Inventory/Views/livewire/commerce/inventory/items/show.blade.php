<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Show;

/** @var Show $this */
/** @var list<array<string, mixed>> $channelRows */
/** @var list<array{id: string, label: string, description: string|null, entries: list<array{code: string, severity: string, label: string, description?: string, action?: string}>}> $extensionReadinessPanels */
?>

<div>
    <x-slot name="title">{{ $item->title }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$item->title" :subtitle="$item->sku">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        {{-- Action feedback surfaces as a fixed top-right toast so it is visible no
             matter where on the page the action was triggered (e.g. the Push button
             low in the channels card). Success/warning self-dismiss; errors persist. --}}
        <x-ui.flash-stack>
            @if (session('success'))
                <div
                    wire:key="flash-success-{{ md5((string) session('success')) }}"
                    x-data="{ show: true }"
                    x-init="setTimeout(() => show = false, 5000)"
                    x-show="show"
                    x-transition.opacity.scale.duration.200ms
                >
                    <x-ui.flash variant="success">{{ session('success') }}</x-ui.flash>
                </div>
            @endif

            @if (session('warning'))
                <div
                    wire:key="flash-warning-{{ md5((string) session('warning')) }}"
                    x-data="{ show: true }"
                    x-init="setTimeout(() => show = false, 7000)"
                    x-show="show"
                    x-transition.opacity.scale.duration.200ms
                >
                    <x-ui.flash variant="warning">{{ session('warning') }}</x-ui.flash>
                </div>
            @endif

            @if (session('error'))
                <div wire:key="flash-error-{{ md5((string) session('error')) }}" x-data x-transition.opacity.scale.duration.200ms>
                    <x-ui.flash variant="error">{{ session('error') }}</x-ui.flash>
                </div>
            @endif
        </x-ui.flash-stack>

        @php
            $readyChannelCount = collect($channelRows)->where('can_push', true)->count();
            $listedChannelCount = collect($channelRows)->where('listed', true)->count();
            $blockedChannelCount = collect($channelRows)->where('readiness_status', 'blocked')->count();
        @endphp

        <x-ui.card>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</span>
                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Qty') }}</span>
                    <span class="text-sm font-medium text-ink tabular-nums">{{ $item->quantity_on_hand }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Target price') }}</span>
                    <span class="text-sm font-medium text-ink tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Channels') }}</span>
                    <x-ui.badge variant="accent">{{ __('Listed :count', ['count' => $listedChannelCount]) }}</x-ui.badge>
                    <x-ui.badge :variant="$readyChannelCount > 0 ? 'success' : 'default'">{{ __('Ready :count', ['count' => $readyChannelCount]) }}</x-ui.badge>
                    @if ($blockedChannelCount > 0)
                        <x-ui.badge variant="warning">{{ __('Blocked :count', ['count' => $blockedChannelCount]) }}</x-ui.badge>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <x-ui.card id="item-facts">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Details') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Inventory facts, pricing, status, storage location, and private notes.') }}</p>
                        </div>
                        <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                    </div>

                    @if ($this->canEdit())
                        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <x-ui.edit-in-place.text
                                :label="__('SKU')"
                                :value="$item->sku"
                                field="sku"
                                save-method="saveField"
                                maxlength="64"
                                monospace
                                :help="__('Seller-controlled item code, required and unique within the operating company.')"
                                :error="$errors->first('sku')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Title')"
                                :value="$item->title"
                                field="title"
                                save-method="saveField"
                                :help="__('Buyer-facing item name used as the listing title when the item is published.')"
                                :error="$errors->first('title')"
                            />

                            <x-ui.edit-in-place.select
                                :label="__('Status')"
                                :value="$item->status"
                                field="status"
                                save-method="saveField"
                                :help="__('Lifecycle stage from draft through ready, listed, sold, and archived.')"
                                :error="$errors->first('status')"
                            >
                                <x-slot name="read">
                                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                                </x-slot>

                                @foreach ($statuses as $statusOption)
                                    <option value="{{ $statusOption }}">{{ __(Illuminate\Support\Str::headline($statusOption)) }}</option>
                                @endforeach
                            </x-ui.edit-in-place.select>

                            <x-ui.edit-in-place.text
                                :label="__('Qty')"
                                :value="$item->quantity_on_hand"
                                field="quantity_on_hand"
                                save-method="saveField"
                                inputmode="numeric"
                                tabular
                                :help="__('Units available for this SKU or tracked item. Use 1 for one-off used parts.')"
                                :error="$errors->first('quantity_on_hand')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Storage location')"
                                :value="$item->storage_location"
                                field="storage_location"
                                save-method="saveField"
                                :empty="__('No location set.')"
                                :help="__('Internal place to find this stock.')"
                                :error="$errors->first('storage_location')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Unit Cost')"
                                :value="$this->formatMoneyInput($item->unit_cost_amount)"
                                :display="$this->formatMoney($item->unit_cost_amount, $item->currency_code)"
                                field="unit_cost_amount"
                                save-method="saveMoneyField"
                                inputmode="decimal"
                                tabular
                                :help="__('Private acquisition cost. Used later to calculate gross margin.')"
                                :error="$errors->first('unit_cost_amount')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Target Price')"
                                :value="$this->formatMoneyInput($item->target_price_amount)"
                                :display="$this->formatMoney($item->target_price_amount, $item->currency_code)"
                                field="target_price_amount"
                                save-method="saveMoneyField"
                                inputmode="decimal"
                                tabular
                                :help="__('Intended selling price and default listing price until a real sale is recorded.')"
                                :error="$errors->first('target_price_amount')"
                            />

                            <x-ui.currency-combobox
                                id="inventory-item-show-currency"
                                wire:model.live="currencyCode"
                                :label="__('Currency')"
                                required
                                :error="$errors->first('currencyCode')"
                            />

                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Created') }}</dt>
                                <dd class="text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <x-ui.edit-in-place.textarea
                                :label="__('Listing description')"
                                :value="$item->description"
                                field="description"
                                save-method="saveField"
                                :empty="__('No listing description yet.')"
                                rows="6"
                                :help="__('Buyer-facing copy. This is the marketplace listing body (the eBay “See full item description”) and is pushed to each channel. Pulling from a channel fills it in.')"
                                :error="$errors->first('description')"
                            />
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <x-ui.edit-in-place.textarea
                                :label="__('Notes')"
                                :value="$item->notes"
                                field="notes"
                                save-method="saveField"
                                :empty="__('No notes captured yet.')"
                                rows="5"
                                :help="__('Private working notes. Never published to buyers or sent to a marketplace.')"
                                :error="$errors->first('notes')"
                            />
                        </dl>
                    @else
                        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                                <dd class="mt-1">
                                    <x-ui.badge :variant="$this->statusVariant($item->status)">{{ __(Illuminate\Support\Str::headline($item->status)) }}</x-ui.badge>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Unit Cost') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->unit_cost_amount, $item->currency_code) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Target Price') }}</dt>
                                <dd class="mt-1 text-sm text-ink tabular-nums">{{ $this->formatMoney($item->target_price_amount, $item->currency_code) }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Created') }}</dt>
                                <dd class="mt-1 text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Listing description') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->description ?: __('No listing description yet.') }}</dd>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Notes') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->notes ?: __('No notes captured yet.') }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                <x-ui.card id="catalog-fit">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Catalog Fit') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Pick the reusable category and template that decide which structured fields apply to this item.') }}</p>
                        </div>
                        <div class="flex flex-wrap justify-end gap-2">
                            <x-ui.badge>{{ $item->category?->path_label ?? __('No category') }}</x-ui.badge>
                            <x-ui.badge>{{ $item->productTemplate?->name ?? __('No template') }}</x-ui.badge>
                        </div>
                    </div>

                    @if ($this->canEdit())
                        <form wire:submit="saveCatalogAssignment" class="grid grid-cols-1 gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
                            <x-ui.select
                                id="item-catalog-category"
                                wire:model.live="catalogCategoryId"
                                label="{{ __('Category') }}"
                                :help="__('Broad reusable group for this sellable item.')"
                                :error="$errors->first('catalogCategoryId')"
                            >
                                <option value="">{{ __('No category') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->path_label }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.select
                                id="item-catalog-template"
                                wire:model.live="catalogProductTemplateId"
                                label="{{ __('Template') }}"
                                :help="__('Repeatable item type. Choosing a categorized template also selects its category.')"
                                :error="$errors->first('catalogProductTemplateId')"
                            >
                                <option value="">{{ __('No template') }}</option>
                                @foreach ($productTemplates as $template)
                                    @continue($catalogCategoryId !== null && $template->category_id !== null && $template->category_id !== (int) $catalogCategoryId)
                                    <option value="{{ $template->id }}">
                                        {{ $template->name }}
                                        @if ($template->category)
                                            {{ __('(:category)', ['category' => $template->category->path_label]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.button type="submit" variant="primary">
                                <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                {{ __('Save Fit') }}
                            </x-ui.button>
                        </form>
                    @else
                        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $item->category?->path_label ?? __('No category') }}</dd>
                            </div>
                            <div>
                                <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template') }}</dt>
                                <dd class="mt-1 text-sm text-ink">{{ $item->productTemplate?->name ?? __('No template') }}</dd>
                            </div>
                        </dl>
                    @endif

                    @if ($this->ebayCategorySettingsUrl())
                        @php
                            $ebayCategory = $this->ebayCategoryMapping();
                        @endphp
                        <div class="mt-4 border-t border-border-default pt-4">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('eBay category') }}</p>

                            @if ($item->productTemplate === null)
                                <p class="mt-1 text-sm text-muted">{{ __('Assign a template above — the eBay category is mapped per template.') }}</p>
                            @elseif ($ebayCategory)
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink">
                                    <x-ui.badge variant="success">{{ __('Mapped') }}</x-ui.badge>
                                    <span class="font-mono">{{ $ebayCategory['category_id'] }}{{ $ebayCategory['category_tree_id'] ? ' · '.__('tree :tree', ['tree' => $ebayCategory['category_tree_id']]) : '' }}</span>
                                    <a href="{{ $this->ebayCategorySettingsUrl() }}" class="font-medium text-accent hover:underline" wire:navigate>{{ __('Change') }}</a>
                                </div>
                            @else
                                <p class="mt-1 text-sm text-muted">
                                    {{ __('“:template” is not mapped to an eBay category yet.', ['template' => $item->productTemplate->name]) }}
                                    <a href="{{ $this->ebayCategorySettingsUrl() }}" class="ml-1 font-medium text-accent hover:underline" wire:navigate>{{ __('Map it in eBay settings → Categories') }}</a>
                                </p>
                            @endif

                            <p class="mt-1 text-xs text-muted">{{ __('Set per template, so every item of this type shares the same eBay category.') }}</p>
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card id="fitment">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Fitment') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Vehicle or application compatibility confirmed for this item. Marketplace publishing will use this instead of title text alone.') }}</p>
                        </div>
                        <x-ui.badge>{{ $item->fitments->count() }}</x-ui.badge>
                    </div>

                    @if ($item->fitments->isEmpty())
                        <x-ui.alert variant="info">
                            {{ __('No fitment captured yet. Add compatible vehicles, or explicitly mark the item as universal fit when that is true.') }}
                        </x-ui.alert>
                    @else
                        <div class="space-y-3">
                            @foreach ($item->fitments as $fitment)
                                <div wire:key="item-fitment-{{ $fitment->id }}" class="flex items-start justify-between gap-3 border-b border-border-default pb-3 last:border-0 last:pb-0">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-medium text-ink">
                                                @if ($fitment->is_universal)
                                                    {{ __('Universal fit') }}
                                                @else
                                                    {{ collect([$fitment->display_year, $fitment->display_make, $fitment->display_model, $fitment->display_trim, $fitment->display_engine])->filter()->implode(' ') }}
                                                @endif
                                            </p>
                                            <x-ui.badge>{{ __(Illuminate\Support\Str::headline($fitment->confidence)) }}</x-ui.badge>
                                            <x-ui.badge>{{ __(Illuminate\Support\Str::headline($fitment->source)) }}</x-ui.badge>
                                        </div>

                                        @if (! $fitment->is_universal && ($fitment->compatibility_properties ?? []) !== [])
                                            <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                                                @foreach ($fitment->compatibility_properties as $name => $value)
                                                    <div class="flex gap-1">
                                                        <dt class="font-medium text-ink">{{ $name }}:</dt>
                                                        <dd>{{ $value }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif

                                        @if ($fitment->notes)
                                            <p class="mt-2 text-sm text-muted">{{ $fitment->notes }}</p>
                                        @endif
                                    </div>

                                    @if ($this->canEdit())
                                        <div class="flex shrink-0 items-center gap-1">
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="editFitment({{ $fitment->id }})">
                                                <x-icon name="heroicon-o-pencil-square" class="h-4 w-4" />
                                                {{ __('Edit') }}
                                            </x-ui.button>

                                            <button
                                                type="button"
                                                wire:click="deleteFitment({{ $fitment->id }})"
                                                wire:confirm="{{ __('Remove this fitment entry?') }}"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-muted hover:bg-surface-subtle hover:text-status-danger"
                                                aria-label="{{ __('Remove fitment') }}"
                                                title="{{ __('Remove') }}"
                                            >
                                                <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <div class="mt-4 space-y-4 border-t border-border-default pt-4">
                            <form wire:submit="{{ $editingFitmentId === null ? 'addFitment' : 'updateFitment' }}" class="space-y-4">
                                @if ($editingFitmentId !== null)
                                    <x-ui.alert variant="info">
                                        {{ __('Editing fitment entry. Save changes or cancel before adding another entry.') }}
                                    </x-ui.alert>
                                @endif

                                <x-ui.checkbox
                                    id="item-fitment-universal"
                                    wire:model.live="fitmentUniversal"
                                    :label="__('Universal fit')"
                                    :help="__('Use only when the part intentionally fits broadly and does not need vehicle-specific compatibility.')"
                                />

                                @unless ($fitmentUniversal)
                                    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                                        <x-ui.input id="item-fitment-year" wire:model="fitmentYear" :label="__('Year')" :error="$errors->first('fitmentYear')" />
                                        <x-ui.input id="item-fitment-make" wire:model="fitmentMake" :label="__('Make')" :error="$errors->first('fitmentMake')" />
                                        <x-ui.input id="item-fitment-model" wire:model="fitmentModel" :label="__('Model')" :error="$errors->first('fitmentModel')" />
                                        <x-ui.input id="item-fitment-trim" wire:model="fitmentTrim" :label="__('Trim')" :error="$errors->first('fitmentTrim')" />
                                        <x-ui.input id="item-fitment-engine" wire:model="fitmentEngine" :label="__('Engine')" :error="$errors->first('fitmentEngine')" />
                                    </div>
                                @endunless

                                <x-ui.textarea
                                    id="item-fitment-notes"
                                    wire:model="fitmentNotes"
                                    :label="__('Fitment notes')"
                                    rows="2"
                                    :help="__('Optional source or qualifier for this compatibility claim.')"
                                    :error="$errors->first('fitmentNotes')"
                                />

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <x-icon name="{{ $editingFitmentId === null ? 'heroicon-o-plus' : 'heroicon-o-check' }}" class="h-4 w-4" />
                                        {{ $editingFitmentId === null ? __('Add fitment') : __('Save fitment') }}
                                    </x-ui.button>

                                    @if ($editingFitmentId !== null)
                                        <x-ui.button type="button" variant="ghost" size="sm" wire:click="cancelFitmentEdit">
                                            {{ __('Cancel') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            </form>

                            @if ($editingFitmentId === null && ($canBootstrapFitmentFromAttributes || $fitmentSourceItems->isNotEmpty()))
                                <div class="grid gap-4 border-t border-border-default pt-4 lg:grid-cols-2">
                                    @if ($canBootstrapFitmentFromAttributes)
                                        <div class="space-y-2">
                                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Bootstrap') }}</p>
                                            <p class="text-sm text-muted">{{ __('Create one fitment entry from configured item attributes such as year, make, model, trim, and engine.') }}</p>
                                            <x-ui.button type="button" variant="outline" size="sm" wire:click="bootstrapFitmentFromAttributes">
                                                <x-icon name="heroicon-o-sparkles" class="h-4 w-4" />
                                                {{ __('Create from attributes') }}
                                            </x-ui.button>
                                        </div>
                                    @endif

                                    @if ($fitmentSourceItems->isNotEmpty())
                                        <form wire:submit="copyFitmentsFromItem" class="space-y-3">
                                            <x-ui.combobox
                                                id="item-fitment-copy-source"
                                                wire:model="copyFitmentsFromItemId"
                                                :label="__('Copy fitment from item')"
                                                :placeholder="__('Search by SKU or title')"
                                                :options="$fitmentSourceItems->map(fn ($source) => [
                                                    'value' => (string) $source->id,
                                                    'label' => $source->sku . ' — ' . $source->title . ' (' . trans_choice(':count entry|:count entries', $source->fitments_count, ['count' => $source->fitments_count]) . ')',
                                                ])->all()"
                                                :error="$errors->first('copyFitmentsFromItemId')"
                                            />

                                            <x-ui.button type="submit" variant="outline" size="sm">
                                                <x-icon name="heroicon-o-clipboard-document" class="h-4 w-4" />
                                                {{ __('Copy fitment') }}
                                            </x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            @endif

                            <form wire:submit="importFitments" class="space-y-3 border-t border-border-default pt-4">
                                <x-ui.textarea
                                    id="item-fitment-bulk"
                                    wire:model="fitmentBulk"
                                    :label="__('Bulk fitment')"
                                    rows="4"
                                    :help="__('One row per vehicle/application: Year, Make, Model, Trim, Engine, Notes. CSV quoting is supported.')"
                                    :error="$errors->first('fitmentBulk')"
                                />

                                <x-ui.button type="submit" variant="outline" size="sm">
                                    <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                                    {{ __('Import fitment rows') }}
                                </x-ui.button>
                            </form>
                        </div>
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card id="listing-channels" x-data="{ helpOpen: false }">
                    <div class="mb-4 flex items-center gap-2">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Listing & Channels') }}</h2>
                        <x-ui.help @click="helpOpen = ! helpOpen" ::aria-expanded="helpOpen" />
                    </div>

                    <div
                        x-cloak
                        x-show="helpOpen"
                        x-transition
                        class="mb-4 overflow-hidden rounded-2xl border border-border-default bg-surface-card text-sm text-muted shadow-sm"
                        @click="helpOpen = false"
                        role="note"
                        aria-label="{{ __('Click to dismiss') }}"
                    >
                        <div class="space-y-2 p-4">
                            <p>{{ __('Publish or update this one item on each marketplace — this is the push side of the workflow.') }}</p>
                            <p>{{ __('Pulling listings and orders in from a marketplace happens on that channel’s own page. Here you push this item out, one channel at a time, once it is ready.') }}</p>
                            <p><span class="font-medium text-ink">{{ __('Recheck') }}</span> — {{ __('re-runs that channel’s readiness check (category, price, fitment, photos, policies). An item can only be listed once its check passes.') }}</p>
                            <p><span class="font-medium text-ink">{{ __('List / Push') }}</span> — {{ __('List publishes this item as a new listing; Push updates the existing listing with the item’s current data. Live channels confirm before writing.') }}</p>
                        </div>
                    </div>

                    @if (! $this->canPushToMarketplace())
                        <x-ui.alert variant="info" class="mb-4">{{ __('You can review channel readiness here. Marketplace push actions require inventory edit and marketplace execute permission.') }}</x-ui.alert>
                    @endif

                    @if ($channelRows === [])
                        <x-ui.alert variant="info">{{ __('No marketplace channels are registered yet.') }}</x-ui.alert>
                    @else
                        <div class="space-y-3">
                        @foreach ($channelRows as $row)
                            @php
                                $listing = $row['listing'];
                                $draft = $row['draft'];
                                $gapLinks = [
                                    'item_facts' => ['label' => __('Edit details'), 'href' => '#item-facts'],
                                    'fitment' => ['label' => __('Edit fitment'), 'href' => '#fitment'],
                                    'photos' => ['label' => __('Edit media'), 'href' => '#photos'],
                                    'attributes' => ['label' => __('Edit identifiers'), 'href' => '#attributes'],
                                    'settings' => ['label' => __('Open channel settings'), 'href' => $row['settings_url'] ? $row['settings_url'].'#defaults' : null],
                                    'ebay_categories' => ['label' => __('Map eBay category'), 'href' => $row['settings_url'] ? $row['settings_url'].'#categories' : null],
                                ];
                                $firstGaps = collect($row['blockers'])->take(3);

                                // One badge answers "where is this listing?" instead of three
                                // overlapping ones (Listed + Ready + ACTIVE). A live, active
                                // listing reads "Listed"; an ended/withdrawn one shows its eBay
                                // status; an unpublished item shows its push-readiness instead.
                                if ($row['listed']) {
                                    $stateLabel = __('Listed');
                                    $stateVariant = 'success';
                                } elseif ($listing) {
                                    $stateLabel = Illuminate\Support\Str::headline($listing->status ?? 'unknown');
                                    $stateVariant = $this->listingStatusVariant($listing->status);
                                } else {
                                    $stateLabel = Illuminate\Support\Str::headline($row['readiness_status']);
                                    $stateVariant = $row['readiness_variant'];
                                }
                            @endphp

                            <div wire:key="item-channel-{{ $row['key'] }}" class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-medium text-ink">{{ $row['label'] }}</p>
                                            @if ($row['environment'])
                                                <x-ui.badge :variant="$row['environment'] === 'live' ? 'warning' : 'default'">{{ __(Illuminate\Support\Str::headline($row['environment'])) }}</x-ui.badge>
                                            @endif
                                            <x-ui.badge :variant="$stateVariant">{{ __($stateLabel) }}</x-ui.badge>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 flex-col items-end gap-2">
                                        @if ($this->canEdit())
                                            <x-ui.button type="button" variant="ghost" size="sm" wire:click="refreshChannelReadiness('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="refreshChannelReadiness('{{ $row['key'] }}')" title="{{ __('Re-run the readiness check on this channel only') }}">
                                                <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                                                {{ __('Recheck') }}
                                            </x-ui.button>
                                        @endif

                                        @if ($this->canPushToMarketplace())
                                            @if ($row['requires_confirmation'])
                                                <x-ui.button type="button" :variant="$row['can_push'] ? 'primary' : 'outline'" size="sm" wire:click="pushChannel('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="pushChannel('{{ $row['key'] }}')" :disabled="! $row['can_push']" wire:confirm="{{ __('This will write to the live :channel marketplace. Continue?', ['channel' => $row['label']]) }}">
                                                    <x-icon name="{{ $row['listed'] ? 'heroicon-o-arrow-up-tray' : 'heroicon-o-plus-circle' }}" class="h-4 w-4" />
                                                    {{ $row['listed'] ? __('Push') : __('List') }}
                                                </x-ui.button>
                                            @else
                                                <x-ui.button type="button" :variant="$row['can_push'] ? 'primary' : 'outline'" size="sm" wire:click="pushChannel('{{ $row['key'] }}')" wire:loading.attr="disabled" wire:target="pushChannel('{{ $row['key'] }}')" :disabled="! $row['can_push']">
                                                    <x-icon name="{{ $row['listed'] ? 'heroicon-o-arrow-up-tray' : 'heroicon-o-plus-circle' }}" class="h-4 w-4" />
                                                    {{ $row['listed'] ? __('Push') : __('List') }}
                                                </x-ui.button>
                                            @endif
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3 border-t border-border-default pt-3 text-xs text-muted">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                        @if ($listing?->listing_url)
                                            <a href="{{ $listing->listing_url }}" target="_blank" rel="noreferrer" class="inline-flex items-center gap-1 font-medium text-accent hover:underline">
                                                <x-icon name="heroicon-o-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                                                {{ __('Listing page') }}
                                            </a>
                                        @elseif ($listing?->external_listing_id)
                                            <span class="font-mono">{{ $listing->external_listing_id }}</span>
                                        @endif

                                        @if ($row['index_url'])
                                            <a href="{{ $row['index_url'] }}" class="inline-flex items-center gap-1 font-medium text-accent hover:underline" wire:navigate>
                                                <x-icon name="heroicon-o-link" class="h-3.5 w-3.5" />
                                                {{ __('Channel page') }}
                                            </a>
                                        @endif
                                    </div>

                                    @unless ($listing?->listing_url || $listing?->external_listing_id)
                                        <p class="mt-1">{{ __('Will use target price: :price', ['price' => $this->formatMoney($row['price_amount'], $row['currency_code'])]) }}</p>
                                    @endunless

                                    @if ($draft)
                                        <p class="mt-1">{{ __('Checked :time', ['time' => $draft->metadata_checked_at?->diffForHumans() ?? __('never')]) }}</p>
                                    @else
                                        <p class="mt-1">{{ __('Run checks before the first push.') }}</p>
                                    @endif

                                    @if ($row['blockers'] !== [])
                                        <div class="mt-2">
                                            <p class="font-medium text-status-danger">{{ trans_choice(':count blocker|:count blockers', count($row['blockers']), ['count' => count($row['blockers'])]) }}</p>
                                            <ul class="mt-1 space-y-1">
                                                @foreach ($firstGaps as $gap)
                                                    <li class="flex gap-1.5">
                                                        <span class="text-status-danger">•</span>
                                                        <span>
                                                            {{ $gap['label'] ?? __('Readiness blocker') }}
                                                            @if (isset($gap['action'], $gapLinks[$gap['action']]) && $gapLinks[$gap['action']]['href'])
                                                                @if ($gap['action'] === 'settings')
                                                                    <a href="{{ $gapLinks[$gap['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline" wire:navigate>{{ $gapLinks[$gap['action']]['label'] }}</a>
                                                                @else
                                                                    <a href="{{ $gapLinks[$gap['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline">{{ $gapLinks[$gap['action']]['label'] }}</a>
                                                                @endif
                                                            @endif
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @elseif ($row['readiness_status'] === 'ready')
                                        <p class="mt-2">{{ __('Ready to publish or revise.') }}</p>
                                    @endif

                                    @if ($row['warnings'] !== [])
                                        <div class="mt-2">
                                            <p class="font-medium text-status-warning">{{ trans_choice(':count warning|:count warnings', count($row['warnings']), ['count' => count($row['warnings'])]) }}</p>
                                            <ul class="mt-1 space-y-1">
                                                @foreach ($row['warnings'] as $warning)
                                                    <li class="flex gap-1.5">
                                                        <span class="text-status-warning">•</span>
                                                        <span>
                                                            {{ $warning['label'] ?? __('Listing warning') }}
                                                            @if (isset($warning['action'], $gapLinks[$warning['action']]) && $gapLinks[$warning['action']]['href'])
                                                                @if ($warning['action'] === 'settings')
                                                                    <a href="{{ $gapLinks[$warning['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline" wire:navigate>{{ $gapLinks[$warning['action']]['label'] }}</a>
                                                                @else
                                                                    <a href="{{ $gapLinks[$warning['action']]['href'] }}" class="ml-1 font-medium text-accent hover:underline">{{ $gapLinks[$warning['action']]['label'] }}</a>
                                                                @endif
                                                            @endif
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        </div>
                    @endif
                </x-ui.card>

                @foreach ($extensionReadinessPanels as $panel)
                    @php
                        $attentionEntries = collect($panel['entries'])->whereIn('severity', ['blocker', 'warning', 'suggestion']);
                        $attentionCount = $attentionEntries->count();
                        $panelHasBlocker = $attentionEntries->contains('severity', 'blocker');
                    @endphp
                    <x-ui.card id="extension-readiness-{{ Illuminate\Support\Str::slug($panel['id']) }}" wire:key="extension-readiness-{{ $panel['id'] }}" x-data="{ open: {{ $panelHasBlocker ? 'true' : 'false' }} }">
                        {{-- Collapsed by default so this supplementary checklist does not compete
                             with the source cards it links to. It auto-opens when a blocker needs
                             action; each entry deep-links to the exact field to fix. --}}
                        <button type="button" @click="open = ! open" x-bind:aria-expanded="open.toString()" class="flex w-full items-start justify-between gap-3 text-left">
                            <div>
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __($panel['label']) }}</h2>
                                @if ($panel['description'])
                                    <p class="mt-1 text-sm text-muted">{{ __($panel['description']) }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($attentionCount > 0)
                                    <x-ui.badge :variant="$panelHasBlocker ? 'danger' : 'warning'">{{ __(':count to improve', ['count' => $attentionCount]) }}</x-ui.badge>
                                @else
                                    <x-ui.badge variant="success">{{ __('All clear') }}</x-ui.badge>
                                @endif
                                <x-icon name="heroicon-o-chevron-down" class="h-4 w-4 text-muted transition-transform" ::class="open && 'rotate-180'" />
                            </div>
                        </button>

                        <div x-show="open" x-cloak x-transition class="mt-3 space-y-2">
                            @foreach ($panel['entries'] as $entry)
                                @php($entryVariant = match ($entry['severity']) {
                                    'success' => 'success',
                                    'blocker' => 'danger',
                                    'warning' => 'warning',
                                    default => 'info',
                                })
                                @php($actionTargets = [
                                    'item_facts' => ['label' => __('Edit item facts'), 'href' => '#item-facts'],
                                    'catalog_fit' => ['label' => __('Edit catalog fit'), 'href' => '#catalog-fit'],
                                    'fitment' => ['label' => __('Edit fitment'), 'href' => '#fitment'],
                                    'attributes' => ['label' => __('Edit attributes'), 'href' => '#attributes'],
                                    'photos' => ['label' => __('Edit photos'), 'href' => '#photos'],
                                ])

                                <div wire:key="extension-readiness-{{ $panel['id'] }}-{{ $entry['code'] }}" class="rounded-2xl border border-border-default bg-surface-subtle p-3">
                                    <div class="flex items-start gap-3">
                                        <x-ui.badge :variant="$entryVariant">{{ __(Illuminate\Support\Str::headline($entry['severity'])) }}</x-ui.badge>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-ink">{{ __($entry['label']) }}</p>
                                            @if (isset($entry['description']))
                                                <p class="mt-1 text-sm text-muted">{{ __($entry['description']) }}</p>
                                            @endif
                                            @if (isset($entry['action'], $actionTargets[$entry['action']]))
                                                <a href="{{ $actionTargets[$entry['action']]['href'] }}" class="mt-2 inline-flex text-sm font-medium text-accent hover:underline">
                                                    {{ $actionTargets[$entry['action']]['label'] }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endforeach

                <x-ui.card id="photos">
                    <div
                        x-data="{ dragging: false, dragDepth: 0, autoUploadOnFinish: false }"
                        class="relative"
                        @dragenter.prevent.stop="
                            dragDepth++;
                            dragging = true;
                        "
                        @dragover.prevent.stop="dragging = true"
                        @dragleave.prevent.stop="
                            dragDepth = Math.max(0, dragDepth - 1);
                            if (dragDepth === 0) dragging = false;
                        "
                        x-on:livewire-upload-finish.window="
                            if (!autoUploadOnFinish) return;
                            autoUploadOnFinish = false;
                            $wire.uploadPhotos();
                        "
                        x-on:livewire-upload-error.window="autoUploadOnFinish = false"
                        @drop.prevent.stop="
                            dragDepth = 0;
                            dragging = false;
                            const dt = $event.dataTransfer;
                            if (!dt || !dt.files || dt.files.length === 0) return;
                            $refs.photoInput.files = dt.files;
                            $refs.photoInput.dispatchEvent(new Event('change', { bubbles: true }));
                            autoUploadOnFinish = true;
                        "
                    >
                        <div
                            x-cloak
                            x-show="dragging"
                            class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-accent/70 bg-surface-card/80"
                        >
                            <div class="text-center px-6">
                                <div class="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-surface-subtle text-muted">
                                    <x-icon name="heroicon-o-arrow-up-tray" class="h-5 w-5" />
                                </div>
                                <p class="text-sm font-medium text-ink">{{ __('Drop photos to add them') }}</p>
                                <p class="mt-1 text-xs text-muted">{{ __('Images only. Multiple files supported.') }}</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Media') }}</h2>
                                <p class="mt-1 text-sm text-muted">{{ __('Buyer-facing photos used by marketplace listing drafts.') }}</p>
                            </div>
                            <x-ui.badge>{{ $item->photos->count() }}</x-ui.badge>
                        </div>

                    @if ($item->photos->isEmpty())
                        <p class="text-sm text-muted">{{ __('No photos yet.') }}</p>
                    @else
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ($item->photos as $photo)
                                @php($asset = $photo->mediaAsset)
                                @php($filename = $asset?->original_filename ?? '')
                                <div wire:key="item-photo-{{ $photo->id }}" class="group relative overflow-hidden rounded-2xl border border-border-default bg-surface-subtle">
                                    @if ($asset)
                                        <img
                                            src="{{ $asset->streamUrl() }}"
                                            alt="{{ $filename }}"
                                            class="aspect-square w-full object-cover"
                                            loading="lazy"
                                        />
                                    @endif

                                    @if ($this->canEdit())
                                        <button
                                            type="button"
                                            wire:click="deletePhoto({{ $photo->id }})"
                                            wire:confirm="{{ __('Remove this photo?') }}"
                                            class="absolute right-2 top-2 inline-flex items-center justify-center rounded-full bg-surface-card/90 p-1.5 text-muted opacity-0 shadow-sm transition-opacity group-hover:opacity-100 hover:text-status-danger"
                                            aria-label="{{ __('Remove photo') }}"
                                            title="{{ __('Remove') }}"
                                        >
                                            <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <form wire:submit="uploadPhotos" class="mt-4 flex flex-col gap-3">
                            <div>
                                <label for="item-photos" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Add photos') }}</label>

                                <input
                                    x-ref="photoInput"
                                    id="item-photos"
                                    type="file"
                                    multiple
                                    accept="image/*"
                                    wire:model="photoFiles"
                                    x-on:change="if ($event.target.files && $event.target.files.length > 0) autoUploadOnFinish = true"
                                    class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80"
                                />

                                @error('photoFiles') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                                @error('photoFiles.*') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                                @if (! $errors->has('photoFiles') && ! $errors->has('photoFiles.*'))
                                    <x-ui.field-help :hint="__('Raw photos from phone or desktop. Cleaned versions will be stored later as derived assets.')" />
                                @endif
                            </div>

                        </form>
                    @endif
                    </div>
                </x-ui.card>

                <x-ui.card id="attributes">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Identifiers & Attributes') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Structured facts that power search, fitment evidence, and channel-specific item specifics.') }}</p>
                        </div>
                        <x-ui.badge>{{ $item->catalogAttributeValues->count() }}</x-ui.badge>
                    </div>

                    @if ($item->catalogAttributeValues->isEmpty())
                        <p class="text-sm text-muted">{{ __('No attributes captured yet.') }}</p>
                    @else
                        <div class="space-y-3">
                            @foreach ($item->catalogAttributeValues as $value)
                                <div wire:key="item-attribute-value-{{ $value->id }}" class="flex items-start justify-between gap-3 border-b border-border-default pb-3 last:border-0 last:pb-0">
                                    <div>
                                        <div class="text-sm font-medium text-ink">{{ $value->attribute->name }}</div>
                                        <div class="mt-1 text-sm text-muted">{{ $value->display_value }}</div>
                                    </div>

                                    @if ($this->canEdit())
                                        <button
                                            type="button"
                                            wire:click="removeAttributeValue({{ $value->id }})"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-full text-muted hover:bg-surface-subtle hover:text-status-danger"
                                            aria-label="{{ __('Remove attribute') }}"
                                            title="{{ __('Remove') }}"
                                        >
                                            <x-icon name="heroicon-o-x-mark" class="h-4 w-4" />
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <form wire:submit="saveAttributeValue" class="mt-4 space-y-4 border-t border-border-default pt-4">
                            <x-ui.select
                                id="item-attribute-id"
                                wire:model="selectedAttributeId"
                                label="{{ __('Attribute') }}"
                                :help="__('Structured buyer-facing fact such as OEM number, interchange number, or condition grade.')"
                                :error="$errors->first('selectedAttributeId')"
                            >
                                <option value="">{{ __('Select...') }}</option>
                                @foreach ($availableAttributes as $attribute)
                                    <option value="{{ $attribute->id }}">
                                        {{ $attribute->name }}
                                        @if ($attribute->productTemplate)
                                            {{ __('(:template)', ['template' => $attribute->productTemplate->name]) }}
                                        @elseif ($attribute->category)
                                            {{ __('(:category)', ['category' => $attribute->category->path_label]) }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-ui.select>

                            @if ($selectedAttributeId)
                                <x-ui.input
                                    id="item-attribute-value"
                                    wire:model="attributeValue"
                                    label="{{ __('Value') }}"
                                    :help="__('The value for the selected attribute. These values map to marketplace item specifics later.')"
                                    :error="$errors->first('attributeValue')"
                                />

                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.button type="submit" variant="primary" size="sm">
                                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                        {{ __('Save') }}
                                    </x-ui.button>

                                    <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('commerce.catalog.index') }}" wire:navigate>
                                        <x-icon name="heroicon-o-tag" class="h-4 w-4" />
                                        {{ __('Catalog') }}
                                    </x-ui.button>
                                </div>
                            @else
                                <x-ui.field-help :hint="$availableAttributes->isEmpty() ? __('No applicable attributes exist for this item fit yet. Add global, category, or template attributes in Catalog.') : __('Select an attribute first, then enter its value.')"/>
                            @endif
                        </form>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
