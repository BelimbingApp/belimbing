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

                            <x-ui.edit-in-place.text
                                :label="__('Currency')"
                                :value="$item->currency_code"
                                field="currency_code"
                                save-method="saveField"
                                maxlength="3"
                                monospace
                                :help="__('Applies to this item cost and target price. Snapshotted so later defaults do not rewrite history.')"
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
                            <dt class="mb-1 text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Notes') }}</dt>
                            <dd class="text-sm text-ink whitespace-pre-wrap">{{ $item->notes ?: __('No notes captured yet.') }}</dd>
                        </dl>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div x-data="{ helpOpen: false }">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Listing Descriptions') }}</h2>
                                <x-ui.help @click="helpOpen = !helpOpen" ::aria-expanded="helpOpen" />
                            </div>
                            <x-ui.badge>{{ $item->descriptions->count() }}</x-ui.badge>
                        </div>

                        <div
                            x-cloak
                            x-show="helpOpen"
                            x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                            x-transition:enter-start="max-h-0 opacity-0"
                            x-transition:enter-end="max-h-96 opacity-100"
                            x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                            x-transition:leave-start="max-h-96 opacity-100"
                            x-transition:leave-end="max-h-0 opacity-0"
                            class="mb-3 overflow-hidden rounded-2xl border border-border-default bg-surface-card text-sm text-muted shadow-sm"
                            @click="helpOpen = false"
                            role="note"
                            aria-label="{{ __('Click to dismiss') }}"
                        >
                            <div class="p-4 space-y-2">
                                <p>{{ __('Buyer-facing copy intended for a marketplace listing (not internal notes).') }}</p>
                                <p>{{ __('Each time you add a description, it is saved as a new version (v1, v2, …) so older drafts remain visible.') }}</p>
                                <p>{{ __('Accept marks the one version approved to use right now (only one can be accepted at a time).') }}</p>
                            </div>
                        </div>
                    </div>

                    @if ($item->descriptions->isEmpty())
                        <p class="text-sm text-muted">{{ __('No listing copy versions yet.') }}</p>
                    @else
                        <div class="space-y-4">
                            @foreach ($item->descriptions as $description)
                                <div wire:key="item-description-{{ $description->id }}" class="border-b border-border-default pb-4 last:border-0 last:pb-0">
                                    <div class="flex flex-col gap-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <x-ui.badge>{{ __('v:version', ['version' => $description->version]) }}</x-ui.badge>
                                                @if ($description->is_accepted)
                                                    <x-ui.badge variant="success">{{ __('Accepted') }}</x-ui.badge>
                                                @endif
                                            </div>

                                            @if ($this->canEdit())
                                                <div class="flex items-center gap-2">
                                                    @if (! $description->is_accepted)
                                                        <x-ui.button type="button" variant="outline" size="sm" wire:click="acceptDescription({{ $description->id }})">
                                                            <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                                            {{ __('Accept') }}
                                                        </x-ui.button>
                                                    @endif

                                                    <x-ui.button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        wire:click="deleteDescription({{ $description->id }})"
                                                        wire:confirm="{{ __('Delete this version?') }}"
                                                        aria-label="{{ __('Delete version') }}"
                                                        title="{{ __('Delete') }}"
                                                    >
                                                        <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                    </x-ui.button>
                                                </div>
                                            @endif
                                        </div>

                                        @if ($this->canEdit())
                                            <div class="space-y-2">
                                                <x-ui.edit-in-place.text
                                                    :value="$description->title"
                                                    field="{{ 'descriptions.' . $description->id . '.title' }}"
                                                    save-method="saveDescriptionField"
                                                    :empty="__('Untitled')"
                                                    :error="$errors->first('descriptions.' . $description->id . '.title')"
                                                />

                                                <x-ui.edit-in-place.textarea
                                                    :value="$description->body"
                                                    field="{{ 'descriptions.' . $description->id . '.body' }}"
                                                    save-method="saveDescriptionField"
                                                    rows="6"
                                                    :empty="__('Empty description')"
                                                    :error="$errors->first('descriptions.' . $description->id . '.body')"
                                                />
                                            </div>
                                        @else
                                            <div>
                                                <h3 class="text-sm font-medium text-ink">{{ $description->title }}</h3>
                                                <p class="mt-2 whitespace-pre-wrap text-sm text-muted">{{ $description->body }}</p>
                                            </div>
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
                                :help="__('Short label for this description version.')"
                                :error="$errors->first('descriptionTitle')"
                            />

                            <x-ui.textarea
                                id="item-description-body"
                                wire:model="descriptionBody"
                                label="{{ __('Body') }}"
                                rows="6"
                                required
                                :help="__('Buyer-facing listing copy. Each saved draft becomes a new version.')"
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
                            <x-ui.select
                                id="item-attribute-id"
                                wire:model="selectedAttributeId"
                                label="{{ __('Attribute') }}"
                                :help="__('Structured buyer-facing fact such as OEM number, interchange number, or condition grade.')"
                                :error="$errors->first('selectedAttributeId')"
                            >
                                <option value="">{{ __('Select...') }}</option>
                                @foreach ($availableAttributes as $attribute)
                                    <option value="{{ $attribute->id }}">{{ $attribute->name }}</option>
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
                                <x-ui.field-help :hint="__('Select an attribute first, then enter its value.')"/>
                            @endif
                        </form>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>
</div>
