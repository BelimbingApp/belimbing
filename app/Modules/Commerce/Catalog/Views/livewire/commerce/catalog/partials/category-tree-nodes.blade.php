<div class="space-y-1">
    @foreach ($nodes as $category)
        @php
            $children = $category->treeChildren ?? collect();
            $hasChildren = $children->isNotEmpty();
            $isExpanded = $search !== '' || in_array($category->id, $expandedCategoryIds, true);
            $isSelected = $selectedCategory?->id === $category->id;
        @endphp

        <div wire:key="category-tree-node-{{ $category->id }}">
            <div @class([
                'group flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm transition-colors',
                'bg-accent/10 text-ink ring-1 ring-accent/30' => $isSelected,
                'text-muted hover:bg-surface-card hover:text-ink' => ! $isSelected,
            ]) style="padding-left: {{ 0.5 + ($level * 1.1) }}rem">
                <button
                    type="button"
                    wire:click="toggleCategoryExpansion({{ $category->id }})"
                    @class([
                        'flex h-5 w-5 items-center justify-center rounded text-muted hover:bg-surface-subtle hover:text-ink',
                        'invisible' => ! $hasChildren,
                    ])
                    aria-label="{{ $isExpanded ? __('Collapse category') : __('Expand category') }}"
                >
                    <x-icon name="{{ $isExpanded ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-right' }}" class="h-3.5 w-3.5" />
                </button>

                <button type="button" wire:click="selectCategory({{ $category->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                    <x-icon name="{{ $hasChildren ? 'heroicon-o-folder' : 'heroicon-o-tag' }}" class="h-4 w-4 shrink-0 {{ $isSelected ? 'text-accent' : 'text-muted' }}" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium">{{ $category->name }}</span>
                        <span class="block truncate text-[11px] text-muted">{{ $category->code }}</span>
                    </span>
                    @if ($category->children_count > 0)
                        <span class="rounded-full bg-surface-subtle px-2 py-0.5 text-[11px] text-muted">{{ $category->children_count }}</span>
                    @endif
                </button>
            </div>

            @if ($hasChildren && $isExpanded)
                @include('commerce-catalog::livewire.commerce.catalog.partials.category-tree-nodes', ['nodes' => $children, 'level' => $level + 1])
            @endif
        </div>
    @endforeach
</div>
