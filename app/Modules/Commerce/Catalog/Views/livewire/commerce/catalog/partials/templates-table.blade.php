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
            :label="__('Template attrs')"
        />
        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Applies') }}</th>
        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Items') }}</th>
        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
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
                    <span>{{ $template->category?->path_label ?: __('Any category') }}</span>
                    <x-icon name="heroicon-o-pencil" class="h-3.5 w-3.5 text-muted opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
                <select x-show="editing" x-ref="input" x-model="val" @change="editing = false; $wire.saveTemplateField({{ $template->id }}, 'category_id', val)" @blur="editing = false" class="w-56 rounded border border-accent bg-surface-card px-1 -mx-1 py-0.5 text-sm text-ink focus:outline-none focus:ring-1 focus:ring-accent">
                    <option value="">{{ __('Any category') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->path_label }}</option>
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
<x-ui.badge>{{ __('Inactive') }}</x-ui.badge>
                        @endif
                    </button>
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $template->attributes_count }}</td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                    <span class="font-medium text-ink tabular-nums">{{ $template->applicable_attributes_count }}</span>
                    <span>{{ __('attrs') }}</span>
                    <span class="text-muted/70">/</span>
                    <span class="tabular-nums">{{ $template->required_attributes_count }}</span>
                    <span>{{ __('required') }}</span>
                </td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm text-muted tabular-nums">{{ $template->items_count }}</td>
                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right text-sm">
                    <div class="flex items-center justify-end gap-2">
                        <button type="button" wire:click="manageTemplateAttributes({{ $template->id }})" class="text-accent hover:underline">
                            {{ __('Manage attributes') }}
                        </button>
                        <button type="button" wire:click="addTemplateAttribute({{ $template->id }})" class="text-accent hover:underline">
                            {{ __('Add attribute') }}
                        </button>
                        <a href="{{ route('commerce.inventory.items.create', ['template_id' => $template->id]) }}" class="text-accent hover:underline" wire:navigate>
                            {{ __('Create item') }}
                        </a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No templates found.') }}</td>
            </tr>
        @endforelse
    </tbody>
