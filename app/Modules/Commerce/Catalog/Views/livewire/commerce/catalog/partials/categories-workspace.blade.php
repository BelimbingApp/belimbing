<div class="grid gap-4 lg:grid-cols-[minmax(18rem,24rem)_minmax(0,1fr)]">
    <section class="rounded-xl border border-border-default bg-surface-subtle/40">
        <div class="flex flex-wrap items-center gap-2 px-2 pt-2">
            <x-ui.button type="button" variant="ghost" wire:click="addChildCategory">
                <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                {{ __('Top level') }}
            </x-ui.button>

            @if ($categoryBranchIds !== [])
                @php($allCategoriesExpanded = count(array_intersect($categoryBranchIds, $expandedCategoryIds)) === count($categoryBranchIds))
                <x-ui.button
                    type="button"
                    variant="ghost"
                    class="ml-auto"
                    wire:click="toggleAllCategoryExpansion"
                    aria-label="{{ $allCategoriesExpanded ? __('Collapse all') : __('Expand all') }}"
                    title="{{ $allCategoriesExpanded ? __('Collapse all') : __('Expand all') }}"
                >
                    <x-icon name="{{ $allCategoriesExpanded ? 'heroicon-o-arrows-pointing-in' : 'heroicon-o-arrows-pointing-out' }}" class="h-5 w-5" />
                </x-ui.button>
            @endif
        </div>

        <div class="max-h-[36rem] overflow-y-auto p-2 pt-1">
            @if ($categoryTree->isEmpty())
                <div class="rounded-lg border border-dashed border-border-default bg-surface-card px-4 py-8 text-center">
                    <x-icon name="heroicon-o-folder-open" class="mx-auto h-8 w-8 text-muted" />
                    <h3 class="mt-3 text-sm font-medium text-ink">
                        {{ $search === '' ? __('No categories yet') : __('No categories match your search') }}
                    </h3>
                    <p class="mt-1 text-xs text-muted">
                        {{ $search === '' ? __('Create top-level categories, then add child categories for sellable leaves.') : __('Try a category name, code, description, or full path.') }}
                    </p>
                    @if ($search === '')
                        <x-ui.button type="button" variant="primary" class="mt-4" wire:click="addChildCategory">
                            <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                            {{ __('Add first category') }}
                        </x-ui.button>
                    @endif
                </div>
            @else
                @include('commerce-catalog::livewire.commerce.catalog.partials.category-tree-nodes', ['nodes' => $categoryTree, 'level' => 0])
            @endif
        </div>
    </section>

    <section class="min-w-0 rounded-xl border border-border-default bg-surface-card">
        @include('commerce-catalog::livewire.commerce.catalog.partials.category-inspector')
    </section>
</div>
