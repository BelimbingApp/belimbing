<?php

use App\Modules\Commerce\Catalog\Livewire\Index;

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var Index $this */
?>

<div>
    <x-slot name="title">{{ __('Catalog Workbench') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Catalog Workbench')" :subtitle="__('Define what facts matter for each kind of item, then reuse those fields when listing parts for sale.')">
            <x-slot name="help">
                {{ __('Catalog defines the reusable structure for describing items. Categories group broad kinds of items, templates describe repeatable item types, and attributes are the exact fields to capture. Inventory then stores those facts for one physical item so Lara, eBay publishing, and reports can use consistent data instead of free text.') }}
            </x-slot>

            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('commerce.inventory.items.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-queue-list" class="h-4 w-4" />
                    {{ __('Inventory') }}
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="openCreateModal">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    @if ($tab === 'categories')
                        {{ __('Add Category') }}
                    @elseif ($tab === 'templates')
                        {{ __('Add Template') }}
                    @else
                        {{ __('Add Attribute') }}
                    @endif
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-3 flex flex-col gap-3">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <x-ui.tabs
                        :tabs="$tabs"
                        :default="$tab"
                        size="sm"
                        persistence="none"
                        wire-action="setTab"
                        class="w-full lg:w-auto"
                    >
                        <x-ui.tab id="categories" />
                        <x-ui.tab id="templates" />
                        <x-ui.tab id="attributes" />
                    </x-ui.tabs>

                    <div class="text-xs text-muted">
                        {{ trans_choice(':count row|:count rows', $rows->total(), ['count' => $rows->total()]) }}
                    </div>
                </div>

                <div class="flex flex-col gap-3 lg:flex-row">
                    <div class="flex-1">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ $tab === 'categories' ? __('Search categories by name, code, or description...') : ($tab === 'templates' ? __('Search templates by name, code, or description...') : __('Search attributes by name or code...')) }}"
                        />
                    </div>

                    @if ($tab !== 'categories')
                        <div class="lg:w-64">
                            <x-ui.select id="catalog-filter-category" wire:model.live="filterCategoryId">
                                <option value="">{{ __('All Categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @endif

                    @if ($tab === 'attributes')
                        <div class="lg:w-64">
                            <x-ui.select id="catalog-filter-template" wire:model.live="filterTemplateId">
                                <option value="">{{ __('All Templates') }}</option>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>

                        <div class="lg:w-48">
                            <x-ui.select id="catalog-filter-type" wire:model.live="filterType">
                                <option value="">{{ __('All Types') }}</option>
                                @foreach ($attributeTypes as $type)
                                    <option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    @if ($tab === 'categories')
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="code"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Code')"
                                />
                                <x-ui.sortable-th
                                    column="name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Name')"
                                />
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                                <x-ui.sortable-th
                                    column="product_templates_count"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Templates')"
                                />
                                <x-ui.sortable-th
                                    column="attributes_count"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Attributes')"
                                />
                                <x-ui.sortable-th
                                    column="sort_order"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Sort')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse ($rows as $category)
                                <tr wire:key="category-{{ $category->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-muted"
                                        x-data="{ editing: false, val: @js($category->code) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $category->id }}, 'code', val)" @keydown.escape="editing = false; val = @js($category->code)" @blur="editing = false; $wire.saveCategoryField({{ $category->id }}, 'code', val)" type="text" class="w-48 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                        x-data="{ editing: false, val: @js($category->name) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $category->id }}, 'name', val)" @keydown.escape="editing = false; val = @js($category->name)" @blur="editing = false; $wire.saveCategoryField({{ $category->id }}, 'name', val)" type="text" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y min-w-80 max-w-xl text-sm text-muted"
                                        x-data="{ editing: false, val: @js($category->description ?? '') }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span class="truncate" x-text="val || '-'"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 shrink-0 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $category->id }}, 'description', val)" @keydown.escape="editing = false; val = @js($category->description ?? '')" @blur="editing = false; $wire.saveCategoryField({{ $category->id }}, 'description', val)" type="text" class="w-full rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $category->product_templates_count }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $category->attributes_count }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js((string) $category->sort_order) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $category->id }}, 'sort_order', val)" @keydown.escape="editing = false; val = @js((string) $category->sort_order)" @blur="editing = false; $wire.saveCategoryField({{ $category->id }}, 'sort_order', val)" type="number" min="0" class="w-24 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No categories found.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    @elseif ($tab === 'templates')
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="code"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Code')"
                                />
                                <x-ui.sortable-th
                                    column="name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Name')"
                                />
                                <x-ui.sortable-th
                                    column="category_name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Category')"
                                />
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Description') }}</th>
                                <x-ui.sortable-th
                                    column="is_active"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Status')"
                                />
                                <x-ui.sortable-th
                                    column="attributes_count"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Attributes')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse ($rows as $template)
                                <tr wire:key="template-{{ $template->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-muted"
                                        x-data="{ editing: false, val: @js($template->code) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveTemplateField({{ $template->id }}, 'code', val)" @keydown.escape="editing = false; val = @js($template->code)" @blur="editing = false; $wire.saveTemplateField({{ $template->id }}, 'code', val)" type="text" class="w-48 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                        x-data="{ editing: false, val: @js($template->name) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveTemplateField({{ $template->id }}, 'name', val)" @keydown.escape="editing = false; val = @js($template->name)" @blur="editing = false; $wire.saveTemplateField({{ $template->id }}, 'name', val)" type="text" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js((string) ($template->category_id ?? '')) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span>{{ $template->category?->name ?: __('Any category') }}</span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveTemplateField({{ $template->id }}, 'category_id', val)" @blur="editing = false" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                                            <option value="">{{ __('Any category') }}</option>
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y min-w-80 max-w-xl text-sm text-muted"
                                        x-data="{ editing: false, val: @js($template->description ?? '') }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span class="truncate" x-text="val || '-'"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 shrink-0 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveTemplateField({{ $template->id }}, 'description', val)" @keydown.escape="editing = false; val = @js($template->description ?? '')" @blur="editing = false; $wire.saveTemplateField({{ $template->id }}, 'description', val)" type="text" class="w-full rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <button type="button" wire:click="toggleTemplateActive({{ $template->id }})" class="cursor-pointer">
                                            @if ($template->is_active)
                                                <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge>{{ __('Inactive') }}</x-ui.badge>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $template->attributes_count }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No templates found.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    @else
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="code"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Code')"
                                />
                                <x-ui.sortable-th
                                    column="name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Name')"
                                />
                                <x-ui.sortable-th
                                    column="type"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Type')"
                                />
                                <x-ui.sortable-th
                                    column="category_name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Category')"
                                />
                                <x-ui.sortable-th
                                    column="template_name"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Template')"
                                />
                                <x-ui.sortable-th
                                    column="is_required"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Required')"
                                />
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Options') }}</th>
                                <x-ui.sortable-th
                                    column="sort_order"
                                    :sort-by="$sortBy"
                                    :sort-dir="$sortDir"
                                    :label="__('Sort')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @forelse ($rows as $attribute)
                                @php($optionText = collect($attribute->options ?? [])->implode(', '))
                                <tr wire:key="attribute-{{ $attribute->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-mono text-muted"
                                        x-data="{ editing: false, val: @js($attribute->code) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'code', val)" @keydown.escape="editing = false; val = @js($attribute->code)" @blur="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'code', val)" type="text" class="w-48 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink"
                                        x-data="{ editing: false, val: @js($attribute->name) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'name', val)" @keydown.escape="editing = false; val = @js($attribute->name)" @blur="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'name', val)" type="text" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js($attribute->type) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <x-ui.badge><span>{{ __(Illuminate\Support\Str::headline($attribute->type)) }}</span></x-ui.badge>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'type', val)" @blur="editing = false" class="w-40 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                                            @foreach ($attributeTypes as $type)
                                                <option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js((string) ($attribute->category_id ?? '')) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span>{{ $attribute->category?->name ?: __('Any category') }}</span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'category_id', val)" @blur="editing = false" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                                            <option value="">{{ __('Any category') }}</option>
                                            @foreach ($categories as $category)
                                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js((string) ($attribute->product_template_id ?? '')) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span>{{ $attribute->productTemplate?->name ?: __('Any template') }}</span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'product_template_id', val)" @blur="editing = false" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                                            <option value="">{{ __('Any template') }}</option>
                                            @foreach ($templates as $template)
                                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                        <button type="button" wire:click="toggleAttributeRequired({{ $attribute->id }})" class="cursor-pointer">
                                            @if ($attribute->is_required)
                                                <x-ui.badge variant="warning">{{ __('Required') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge>{{ __('Optional') }}</x-ui.badge>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y min-w-72 max-w-lg text-sm text-muted"
                                        x-data="{ editing: false, val: @js($optionText) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span class="truncate" x-text="val || '-'"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 shrink-0 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'options', val)" @keydown.escape="editing = false; val = @js($optionText)" @blur="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'options', val)" type="text" class="w-full rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                        x-data="{ editing: false, val: @js((string) $attribute->sort_order) }"
                                    >
                                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex cursor-pointer items-center gap-1.5 rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                            <span x-text="val"></span>
                                            <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                                        </div>
                                        <input x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'sort_order', val)" @keydown.escape="editing = false; val = @js((string) $attribute->sort_order)" @blur="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'sort_order', val)" type="number" min="0" class="w-24 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No attributes found.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    @endif
                </table>
            </div>

            <div class="mt-2">
                {{ $rows->links() }}
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-2xl">
        @if ($tab === 'categories')
            <form wire:submit="createCategory" class="space-y-6 p-6">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Category') }}</h2>

                <div class="space-y-4">
                    <x-ui.input id="catalog-category-name" wire:model.live.debounce.300ms="categoryName" label="{{ __('Name') }}" required :error="$errors->first('categoryName')" />
                    <x-ui.input id="catalog-category-code" wire:model="categoryCode" label="{{ __('Code') }}" required :error="$errors->first('categoryCode')" />
                    <x-ui.textarea id="catalog-category-description" wire:model="categoryDescription" label="{{ __('Description') }}" rows="3" :error="$errors->first('categoryDescription')" />
                </div>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @elseif ($tab === 'templates')
            <form wire:submit="createTemplate" class="space-y-6 p-6">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Template') }}</h2>

                <div class="space-y-4">
                    <x-ui.select id="catalog-template-category" wire:model="templateCategoryId" label="{{ __('Category') }}" :error="$errors->first('templateCategoryId')">
                        <option value="">{{ __('Any category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input id="catalog-template-name" wire:model.live.debounce.300ms="templateName" label="{{ __('Name') }}" required :error="$errors->first('templateName')" />
                    <x-ui.input id="catalog-template-code" wire:model="templateCode" label="{{ __('Code') }}" required :error="$errors->first('templateCode')" />
                    <x-ui.textarea id="catalog-template-description" wire:model="templateDescription" label="{{ __('Description') }}" rows="3" :error="$errors->first('templateDescription')" />
                </div>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @else
            <form wire:submit="createAttribute" class="space-y-6 p-6">
                <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Attribute') }}</h2>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.select id="catalog-attribute-category" wire:model="attributeCategoryId" label="{{ __('Category') }}" :error="$errors->first('attributeCategoryId')">
                        <option value="">{{ __('Any category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select id="catalog-attribute-template" wire:model="attributeProductTemplateId" label="{{ __('Template') }}" :error="$errors->first('attributeProductTemplateId')">
                        <option value="">{{ __('Any template') }}</option>
                        @foreach ($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input id="catalog-attribute-name" wire:model.live.debounce.300ms="attributeName" label="{{ __('Name') }}" required :error="$errors->first('attributeName')" />
                    <x-ui.input id="catalog-attribute-code" wire:model="attributeCode" label="{{ __('Code') }}" required :error="$errors->first('attributeCode')" />

                    <x-ui.select id="catalog-attribute-type" wire:model="attributeType" label="{{ __('Type') }}" :error="$errors->first('attributeType')">
                        @foreach ($attributeTypes as $type)
                            <option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="flex items-end">
                        <x-ui.checkbox id="catalog-attribute-required" wire:model="attributeRequired" label="{{ __('Required') }}" />
                    </div>
                </div>

                <x-ui.textarea id="catalog-attribute-options" wire:model="attributeOptions" label="{{ __('Options') }}" rows="3" :error="$errors->first('attributeOptions')" />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.modal>
</div>
