<x-layouts.app :title="__('Relationships') . ' — ' . $company->name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Relationships') . ' — ' . $company->name">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.show', $company) }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to Company') }}
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
            <form method="POST" action="{{ route('admin.companies.relationships.store', $company) }}" class="grid grid-cols-1 gap-4 border-b border-border-default pb-4 md:grid-cols-2">
                @csrf
                <x-ui.select name="related_company_id" label="{{ __('Related Company') }}" :error="$errors->first('related_company_id')">
                    <option value="">{{ __('— Select Company —') }}</option>
                    @foreach($availableCompanies as $availableCompany)
                        <option value="{{ $availableCompany->id }}">{{ $availableCompany->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="relationship_type_id" label="{{ __('Relationship Type') }}" :error="$errors->first('relationship_type_id')">
                    <option value="">{{ __('— Select Type —') }}</option>
                    @foreach($relationshipTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="effective_from" label="{{ __('Effective From') }}" type="date" :error="$errors->first('effective_from')" />
                <x-ui.input name="effective_to" label="{{ __('Effective To') }}" type="date" :error="$errors->first('effective_to')" />
                <div class="md:col-span-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Add Relationship') }}</x-ui.button>
                </div>
            </form>

            <div class="mt-4 -mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Related Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Direction') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective From') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective To') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Active?') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($allRelationships as $item)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><a href="{{ route('admin.companies.show', $item->other) }}" class="text-sm font-medium text-accent hover:underline">{{ $item->other->name }}</a></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink">{{ $item->relationship->type->name }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$item->direction === 'outgoing' ? 'info' : 'default'">{{ ucfirst($item->direction) }}</x-ui.badge></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $item->relationship->effective_from?->format('Y-m-d') ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $item->relationship->effective_to?->format('Y-m-d') ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$item->relationship->isActive() ? 'success' : 'danger'">{{ $item->relationship->isActive() ? __('Yes') : __('No') }}</x-ui.badge></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                    <form method="POST" action="{{ route('admin.companies.relationships.destroy', [$company, $item->relationship]) }}" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this relationship?') }}')">
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
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No relationships found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
