@if ($selectedCategory === null)
    <div class="flex min-h-80 items-center justify-center p-8 text-center">
        <div>
            <x-icon name="heroicon-o-folder-open" class="mx-auto h-10 w-10 text-muted" />
            <h2 class="mt-3 text-base font-semibold text-ink">{{ __('Select a category') }}</h2>
            <p class="mt-1 max-w-md text-sm text-muted">{{ __('Choose a category from the tree to review its path, children, direct attributes, and templates.') }}</p>
        </div>
    </div>
@else
    <div class="border-b border-border-default px-5 py-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="truncate text-xs text-muted">{{ $selectedCategory->path_label }}</p>
                <h2 class="mt-1 truncate text-lg font-semibold text-ink">{{ $selectedCategory->name }}</h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    <x-ui.badge>{{ $selectedCategory->parent_id === null ? __('Top level') : __('Child') }}</x-ui.badge>
                    <x-ui.badge variant="{{ $selectedCategory->children_count > 0 ? 'info' : 'success' }}">{{ $selectedCategory->children_count > 0 ? __('Folder') : __('Leaf') }}</x-ui.badge>
                    <x-ui.badge>{{ trans_choice(':count template|:count templates', $selectedCategory->product_templates_count, ['count' => $selectedCategory->product_templates_count]) }}</x-ui.badge>
                    <x-ui.badge>{{ trans_choice(':count direct attribute|:count direct attributes', $selectedCategory->attributes_count, ['count' => $selectedCategory->attributes_count]) }}</x-ui.badge>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6 p-5">
        <section>
            <h3 class="text-sm font-semibold text-ink">{{ __('Basics') }}</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div x-data="{ editing: false, val: @js($selectedCategory->name) }">
                    <label for="category-name-{{ $selectedCategory->id }}" class="text-xs font-medium text-muted">{{ __('Name') }}</label>
                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="mt-1 cursor-pointer rounded-lg border border-border-default bg-surface-subtle/40 px-3 py-2 text-sm text-ink hover:border-accent">
                        <span x-text="val"></span>
                    </div>
                    <input id="category-name-{{ $selectedCategory->id }}" x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'name', val)" @keydown.escape="editing = false; val = @js($selectedCategory->name)" @blur="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'name', val)" type="text" class="mt-1 w-full rounded-lg border border-accent bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                </div>

                <div x-data="{ editing: false, val: @js($selectedCategory->code) }">
                    <label for="category-code-{{ $selectedCategory->id }}" class="text-xs font-medium text-muted">{{ __('Code') }}</label>
                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="mt-1 cursor-pointer rounded-lg border border-border-default bg-surface-subtle/40 px-3 py-2 font-mono text-sm text-muted hover:border-accent">
                        <span x-text="val"></span>
                    </div>
                    <input id="category-code-{{ $selectedCategory->id }}" x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'code', val)" @keydown.escape="editing = false; val = @js($selectedCategory->code)" @blur="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'code', val)" type="text" class="mt-1 w-full rounded-lg border border-accent bg-surface-card px-3 py-2 font-mono text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                </div>

                <div x-data="{ editing: false, val: @js((string) ($selectedCategory->parent_id ?? '')) }">
                    <label for="category-parent-{{ $selectedCategory->id }}" class="text-xs font-medium text-muted">{{ __('Parent') }}</label>
                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.focus())" class="mt-1 cursor-pointer rounded-lg border border-border-default bg-surface-subtle/40 px-3 py-2 text-sm text-ink hover:border-accent">
                        {{ $selectedCategory->parent?->path_label ?: __('Top level') }}
                    </div>
                    <select id="category-parent-{{ $selectedCategory->id }}" x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'parent_id', val)" @blur="editing = false" class="mt-1 w-full rounded-lg border border-accent bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                        <option value="">{{ __('Top level') }}</option>
                        @foreach ($categories as $category)
                            @if ($category->id !== $selectedCategory->id)
                                <option value="{{ $category->id }}">{{ $category->path_label }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>

                <div x-data="{ editing: false, val: @js((string) $selectedCategory->sort_order) }">
                    <label for="category-sort-order-{{ $selectedCategory->id }}" class="text-xs font-medium text-muted">{{ __('Sort order') }}</label>
                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="mt-1 cursor-pointer rounded-lg border border-border-default bg-surface-subtle/40 px-3 py-2 text-sm text-ink hover:border-accent">
                        <span x-text="val"></span>
                    </div>
                    <input id="category-sort-order-{{ $selectedCategory->id }}" x-show="editing" x-ref="input" x-model="val" @keydown.enter="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'sort_order', val)" @keydown.escape="editing = false; val = @js((string) $selectedCategory->sort_order)" @blur="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'sort_order', val)" type="number" min="0" class="mt-1 w-full rounded-lg border border-accent bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent" />
                </div>

                <div class="md:col-span-2" x-data="{ editing: false, val: @js($selectedCategory->description ?? '') }">
                    <label for="category-description-{{ $selectedCategory->id }}" class="text-xs font-medium text-muted">{{ __('Description') }}</label>
                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="mt-1 min-h-11 cursor-pointer rounded-lg border border-border-default bg-surface-subtle/40 px-3 py-2 text-sm text-muted hover:border-accent">
                        <span x-text="val || @js(__('No description yet.'))"></span>
                    </div>
                    <textarea id="category-description-{{ $selectedCategory->id }}" x-show="editing" x-ref="input" x-model="val" @keydown.meta.enter="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'description', val)" @keydown.ctrl.enter="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'description', val)" @keydown.escape="editing = false; val = @js($selectedCategory->description ?? '')" @blur="editing = false; $wire.saveCategoryField({{ $selectedCategory->id }}, 'description', val)" rows="3" class="mt-1 w-full rounded-lg border border-accent bg-surface-card px-3 py-2 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent"></textarea>
                </div>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-3">
            <div class="rounded-lg border border-border-default bg-surface-subtle/30 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-ink">{{ __('Child categories') }}</h3>
                    <button type="button" wire:click="addChildCategory({{ $selectedCategory->id }})" class="text-xs font-medium text-accent hover:underline">{{ __('Add child') }}</button>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($selectedCategory->children as $child)
                        <button type="button" wire:click="selectCategory({{ $child->id }})" class="flex w-full items-center justify-between gap-3 rounded-md bg-surface-card px-3 py-2 text-left text-sm hover:bg-surface-subtle">
                            <span class="truncate text-ink">{{ $child->name }}</span>
                            <span class="font-mono text-xs text-muted">{{ $child->code }}</span>
                        </button>
                    @empty
                        <p class="rounded-md border border-dashed border-border-default px-3 py-4 text-sm text-muted">{{ __('No child categories. This can be a sellable leaf.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border-default bg-surface-subtle/30 p-4 xl:col-span-2">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-ink">{{ __('Direct attributes') }}</h3>
                    <div class="flex gap-3 text-xs font-medium">
                        <button type="button" wire:click="addCategoryAttribute({{ $selectedCategory->id }})" class="text-accent hover:underline">{{ __('Add attribute') }}</button>
                        <button type="button" wire:click="showCategoryAttributes({{ $selectedCategory->id }})" class="text-accent hover:underline">{{ __('Manage all') }}</button>
                    </div>
                </div>
                <div class="mt-3 divide-y divide-border-default rounded-md border border-border-default bg-surface-card">
                    @forelse ($selectedCategory->attributes as $attribute)
                        <div class="flex flex-wrap items-center gap-3 px-3 py-2 text-sm" wire:key="category-inspector-attribute-{{ $attribute->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-ink">{{ $attribute->name }}</span>
                                    <x-ui.badge>{{ __(Illuminate\Support\Str::headline($attribute->type)) }}</x-ui.badge>
                                    @if ($attribute->productTemplate)
                                        <span class="text-xs text-muted">{{ $attribute->productTemplate->name }}</span>
                                    @endif
                                </div>
                                <div class="mt-0.5 font-mono text-xs text-muted">{{ $attribute->code }}</div>
                            </div>
                            <button type="button" wire:click="toggleAttributeRequired({{ $attribute->id }})" class="shrink-0">
                                <x-ui.badge variant="{{ $attribute->is_required ? 'warning' : null }}">{{ $attribute->is_required ? __('Required') : __('Optional') }}</x-ui.badge>
                            </button>
                        </div>
                    @empty
                        <p class="px-3 py-4 text-sm text-muted">{{ __('No direct attributes yet. Add only fields that apply to this category itself.') }}</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border-default bg-surface-subtle/30 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-ink">{{ __('Templates') }}</h3>
                <div class="flex gap-3 text-xs font-medium">
                    <button type="button" wire:click="addCategoryTemplate({{ $selectedCategory->id }})" class="text-accent hover:underline">{{ __('Add template') }}</button>
                    <button type="button" wire:click="showCategoryTemplates({{ $selectedCategory->id }})" class="text-accent hover:underline">{{ __('Manage all') }}</button>
                </div>
            </div>
            <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                @forelse ($selectedCategory->productTemplates as $template)
                    <div class="rounded-md border border-border-default bg-surface-card px-3 py-2 text-sm" wire:key="category-inspector-template-{{ $template->id }}">
                        <div class="font-medium text-ink">{{ $template->name }}</div>
                        <div class="mt-0.5 font-mono text-xs text-muted">{{ $template->code }}</div>
                    </div>
                @empty
                    <p class="rounded-md border border-dashed border-border-default px-3 py-4 text-sm text-muted md:col-span-2 xl:col-span-3">{{ __('No templates are assigned to this category yet.') }}</p>
                @endforelse
            </div>
        </section>
    </div>
@endif
