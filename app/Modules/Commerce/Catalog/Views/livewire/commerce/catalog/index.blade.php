<?php
/** @var \App\Modules\Commerce\Catalog\Livewire\Index $this */
?>

<div>
    @php
        $pageTitle = match ($tab) {
            'categories' => __('Categories'),
            'templates' => __('Templates'),
            default => __('Attributes'),
        };
        $pageSubtitle = match ($tab) {
            'categories' => __('Browse the selling taxonomy. Select a category to manage its direct setup.'),
            'templates' => __('Define reusable item blueprints, then assign them to inventory and listing workflows.'),
            default => __('Define the exact facts merchants capture for items, categories, and templates.'),
        };
        $pageHelp = match ($tab) {
            'categories' => __('Categories organize the selling taxonomy. Use parent categories as folders and leaf categories for sellable families. Selecting a category shows its children, direct attributes, and templates without mixing in vertical-specific marketplace rules.'),
            'templates' => __('Templates describe repeatable item types, such as Headlight Assembly or Alternator. A template is not a physical item; it is the reusable blueprint inventory can use to capture consistent facts.'),
            default => __('Attributes are the structured fields used by inventory, marketplace publishing, Lara, and reports. Category attributes apply directly to a category; template attributes apply to a reusable item blueprint.'),
        };
    @endphp

    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle">
            <x-slot name="help">
                {{ $pageHelp }}
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
                <div class="flex justify-end text-xs text-muted">
                    {{ trans_choice(':count row|:count rows', $rows->total(), ['count' => $rows->total()]) }}
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
                                    <option value="{{ $category->id }}">{{ $category->path_label }}</option>
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

            @if ($tab === 'categories')
                @include('commerce-catalog::livewire.commerce.catalog.partials.categories-workspace')
            @else
                <x-ui.table
                    container="flush"
                    :caption="$tab === 'templates' ? __('Product templates') : __('Catalog attributes')"
                >
                    <x-slot name="head">
                        @if ($tab === 'templates')
                            @include('commerce-catalog::livewire.commerce.catalog.partials.templates-table', ['section' => 'head'])
                        @else
                            @include('commerce-catalog::livewire.commerce.catalog.partials.attributes-table', ['section' => 'head'])
                        @endif
                    </x-slot>

                    @if ($tab === 'templates')
                        @include('commerce-catalog::livewire.commerce.catalog.partials.templates-table', ['section' => 'body'])
                    @else
                        @include('commerce-catalog::livewire.commerce.catalog.partials.attributes-table', ['section' => 'body'])
                    @endif
                </x-ui.table>

                <div class="mt-2">
                    {{ $rows->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showCreateModal" class="max-w-2xl">
        @if (($createKind ?: $tab) === 'categories')
            @include('commerce-catalog::livewire.commerce.catalog.partials.category-form')
        @elseif (($createKind ?: $tab) === 'templates')
            @include('commerce-catalog::livewire.commerce.catalog.partials.template-form')
        @else
            @include('commerce-catalog::livewire.commerce.catalog.partials.attribute-form')
        @endif
    </x-ui.modal>
</div>
