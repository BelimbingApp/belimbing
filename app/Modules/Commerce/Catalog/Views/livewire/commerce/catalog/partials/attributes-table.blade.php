@if (! isset($section) || $section === 'head')
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
@endif
@if (! isset($section) || $section === 'body')
    @forelse ($rows as $attribute)
        @php($optionText = collect($attribute->options ?? [])->implode(', '))
        <tr wire:key="attribute-{{ $attribute->id }}">
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
                    <span>{{ $attribute->category?->path_label ?: __('Any category') }}</span>
                    <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
                <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveAttributeField({{ $attribute->id }}, 'category_id', val)" @blur="editing = false" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">{{ __('Any category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->path_label }}</option>
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
@endif
