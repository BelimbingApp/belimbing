<x-layouts.app :title="__('Legal Entity Types')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Legal Entity Types')" :subtitle="__('Manage company legal structure categories')">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Companies') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <form method="POST" action="{{ route('admin.companies.legal-entity-types.store') }}" class="grid grid-cols-1 gap-4 border-b border-border-default pb-4 md:grid-cols-2">
                @csrf
                <x-ui.input name="code" value="{{ old('code') }}" label="{{ __('Code') }}" :error="$errors->first('code')" required />
                <x-ui.input name="name" value="{{ old('name') }}" label="{{ __('Name') }}" :error="$errors->first('name')" required />
                <x-ui.textarea name="description" label="{{ __('Description') }}" rows="2" :error="$errors->first('description')">{{ old('description') }}</x-ui.textarea>
                <div class="space-y-4">
                    <x-ui.checkbox name="is_active" value="1" :checked="old('is_active', '1') === '1'" label="{{ __('Active') }}" />
                    <x-ui.button type="submit" variant="primary">{{ __('Add Type') }}</x-ui.button>
                </div>
            </form>

            <div class="mt-4 -mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Description') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($types as $type)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm font-mono text-muted">{{ $type->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y" colspan="3">
                                    <form method="POST" action="{{ route('admin.companies.legal-entity-types.update', $type) }}" class="grid grid-cols-1 gap-2 md:grid-cols-4">
                                        @csrf
                                        @method('PATCH')
                                        <x-ui.input name="name" value="{{ $type->name }}" />
                                        <x-ui.input name="description" value="{{ $type->description }}" />
                                        <x-ui.checkbox name="is_active" value="1" :checked="$type->is_active" label="{{ __('Active') }}" />
                                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                                    </form>
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                    <form method="POST" action="{{ route('admin.companies.legal-entity-types.destroy', $type) }}" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this legal entity type?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                            <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                            {{ __('Delete') }}
                                        </x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No legal entity types found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-2">{{ $types->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
