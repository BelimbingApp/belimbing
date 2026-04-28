<?php

use App\Modules\Commerce\Inventory\Livewire\Items\Show;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var Show $this */
?>

<div>
    <x-slot name="title">{{ $item->title }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$item->title" :subtitle="$item->sku">
            <x-slot name="help">
                <p>{{ __('This page is the durable record for one sellable item. Some fields are private to you; others are buyer-facing and end up on marketplace listings. Hover the cues below if anything is unclear.') }}</p>

                <dl class="mt-3 space-y-2">
                    <div>
                        <dt class="font-medium text-ink">{{ __('SKU') }}</dt>
                        <dd>{{ __('Stable BLB-internal identifier generated when the item is created. It does not change and is independent of any eBay item ID.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Title') }}</dt>
                        <dd>{{ __('Buyer-facing item name. Used as the listing title when the item is published; aim for the same words a buyer would search.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Status') }}</dt>
                        <dd>{{ __('Lifecycle stage: Draft (still being prepared) → Ready (cleared for publishing) → Listed (live on a marketplace) → Sold (durable record kept after listing ends) → Archived (no longer relevant).') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Unit Cost') }}</dt>
                        <dd>{{ __('What this item cost you to acquire, in minor currency units (cents). Private to you. Used to compute gross margin in Insights.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Target Price') }}</dt>
                        <dd>{{ __('Your intended selling price. Buyer-facing — used as the default price when the item is published to a marketplace, and as the basis for the listed amount in reports until a real Sale is recorded.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Currency') }}</dt>
                        <dd>{{ __('Applies to both Unit Cost and Target Price on this item. Snapshotted per item so changing the company default later does not rewrite historical records.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Notes') }}</dt>
                        <dd>{{ __('Your private working surface — quick observations, condition jottings, fitment hints to remember. Never published to buyers and never sent to a marketplace.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Photos') }}</dt>
                        <dd>{{ __('Raw photos you upload from your phone or desktop. Later phases will produce cleaned (background-removed) versions as derived assets so the originals are never overwritten.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Attributes') }}</dt>
                        <dd>{{ __('Structured fields beyond the built-in ones (Title, Notes, Unit Cost, Target Price, Photos). Examples: Year, Make, Model, OEM #, Interchange #, Condition Grade. Define new attribute types in the Catalog Workbench, then enter values for this item here. Attribute values are buyer-facing — they map to marketplace item specifics when the item is published.') }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-ink">{{ __('Listing Descriptions') }}</dt>
                        <dd>{{ __('Buyer-facing description copy for marketplace listings. This is separate from your private Notes above. Each entry is a version: every time you (or Lara) drafts a new description, a new numbered version is added so previous drafts are kept for comparison. Use "Add Version" to write a new draft manually — useful when you want to revise listing copy without losing the prior wording. Click "Accept" on whichever version should be the one that gets published; only one version is the accepted (published) one at a time.') }}</dd>
                    </div>
                </dl>
            </x-slot>

            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <x-ui.card>
                    @if ($this->canEdit())
                        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('SKU') }}</dt>
                                <dd class="text-sm text-ink">
                                    <span class="font-mono">{{ $item->sku }}</span>
                                    <p class="mt-1 text-xs text-muted">{{ __('SKU is generated by BLB and cannot be changed.') }}</p>
                                </dd>
                            </div>

                            <x-ui.edit-in-place.text
                                :label="__('Title')"
                                :value="$item->title"
                                field="title"
                                save-method="saveField"
                                :error="$errors->first('title')"
                            />

                            <x-ui.edit-in-place.select
                                :label="__('Status')"
                                :value="$item->status"
                                field="status"
                                save-method="saveField"
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
                                :label="__('Unit Cost')"
                                :value="$this->formatMoneyInput($item->unit_cost_amount)"
                                :display="$this->formatMoney($item->unit_cost_amount, $item->currency_code)"
                                field="unit_cost_amount"
                                save-method="saveMoneyField"
                                inputmode="decimal"
                                tabular
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
                                :error="$errors->first('target_price_amount')"
                            />

                            <x-ui.edit-in-place.text
                                :label="__('Currency')"
                                :value="$item->currency_code"
                                field="currency_code"
                                save-method="saveField"
                                maxlength="3"
                                monospace
                                :error="$errors->first('currency_code')"
                            />

                            <div>
                                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Created') }}</dt>
                                <dd class="text-sm text-ink" title="{{ $item->created_at?->format('Y-m-d H:i:s') }}">{{ $item->created_at?->diffForHumans() }}</dd>
                            </div>
                        </dl>

                        <dl class="mt-4 border-t border-border-default pt-4">
                            <x-ui.edit-in-place.textarea
                                :label="__('Notes')"
                                :value="$item->notes"
                                field="notes"
                                save-method="saveField"
                                :empty="__('No notes captured yet.')"
                                rows="5"
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
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Notes') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->notes ?: __('No notes captured yet.') }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Listing Descriptions') }}</h2>
                        <x-ui.badge>{{ $item->descriptions->count() }}</x-ui.badge>
                    </div>

                    @if ($item->descriptions->isEmpty())
                        <p class="text-sm text-muted">{{ __('No listing copy versions yet.') }}</p>
                    @else
                        <div class="space-y-4">
                            @foreach ($item->descriptions as $description)
                                <div wire:key="item-description-{{ $description->id }}" class="border-b border-border-default pb-4 last:border-0 last:pb-0">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-sm font-medium text-ink">{{ $description->title }}</h3>
                                                <x-ui.badge>{{ __('v:version', ['version' => $description->version]) }}</x-ui.badge>
                                                @if ($description->is_accepted)
                                                    <x-ui.badge variant="success">{{ __('Accepted') }}</x-ui.badge>
                                                @endif
                                            </div>
                                            <p class="mt-2 whitespace-pre-wrap text-sm text-muted">{{ $description->body }}</p>
                                        </div>

                                        @if ($this->canEdit() && ! $description->is_accepted)
                                            <x-ui.button type="button" variant="outline" size="sm" wire:click="acceptDescription({{ $description->id }})">
                                                <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                                {{ __('Accept') }}
                                            </x-ui.button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canEdit())
                        <form wire:submit="addDescription" class="mt-4 space-y-4 border-t border-border-default pt-4">
                            <x-ui.input
                                id="item-description-title"
                                wire:model="descriptionTitle"
                                label="{{ __('Title') }}"
                                required
                                :error="$errors->first('descriptionTitle')"
                            />

                            <x-ui.textarea
                                id="item-description-body"
                                wire:model="descriptionBody"
                                label="{{ __('Body') }}"
                                rows="6"
                                required
                                :error="$errors->first('descriptionBody')"
                            />

                            <x-ui.button type="submit" variant="primary">
                                <x-icon name="heroicon-o-document-plus" class="h-4 w-4" />
                                {{ __('Add Version') }}
                            </x-ui.button>
                        </form>
                    @endif
                </x-ui.card>
            </div>

            <div class="space-y-6">
                <x-ui.card>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Photos') }}</h2>
                        <x-ui.badge>{{ $item->photos->count() }}</x-ui.badge>
                    </div>

                    @if ($item->photos->isEmpty())
                        <p class="text-sm text-muted">{{ __('No photos yet.') }}</p>
                    @else
                        <div class="grid grid-cols-2 gap-3">
                            @foreach ($item->photos as $photo)
                                @php($filename = $photo->filename)
                                <div wire:key="item-photo-{{ $photo->id }}" class="group relative overflow-hidden rounded-2xl border border-border-default bg-surface-subtle">
                                    <img
                                        src="{{ route('commerce.inventory.items.photos.show', [$item, $photo]) }}"
                                        alt="{{ $filename }}"
                                        class="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />

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
                                    id="item-photos"
                                    type="file"
                                    multiple
                                    accept="image/*"
                                    wire:model="photoFiles"
                                    class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80"
                                />
                                @error('photoFiles') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                                @error('photoFiles.*') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                            </div>

                            <div class="flex items-center gap-2">
                                <x-ui.button type="submit" variant="outline" size="sm" :disabled="empty($photoFiles)">
                                    <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                                    {{ __('Upload') }}
                                </x-ui.button>
                            </div>
                        </form>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Attributes') }}</h2>
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
                            <x-ui.select id="item-attribute-id" wire:model="selectedAttributeId" label="{{ __('Attribute') }}" :error="$errors->first('selectedAttributeId')">
                                <option value="">{{ __('Select') }}</option>
                                @foreach ($availableAttributes as $attribute)
                                    <option value="{{ $attribute->id }}">{{ $attribute->name }}</option>
                                @endforeach
                            </x-ui.select>

                            <x-ui.input
                                id="item-attribute-value"
                                wire:model="attributeValue"
                                label="{{ __('Value') }}"
                                :error="$errors->first('attributeValue')"
                            />

                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="submit" variant="primary" size="sm" :disabled="$availableAttributes->isEmpty()">
                                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                                    {{ __('Save') }}
                                </x-ui.button>

                                <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('commerce.catalog.index') }}" wire:navigate>
                                    <x-icon name="heroicon-o-tag" class="h-4 w-4" />
                                    {{ __('Catalog') }}
                                </x-ui.button>
                            </div>
                        </form>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
