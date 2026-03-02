<x-layouts.app :title="__('Departments') . ' — ' . $company->name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Departments') . ' — ' . $company->name">
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
            <form method="POST" action="{{ route('admin.companies.departments.store', $company) }}" class="grid grid-cols-1 gap-4 border-b border-border-default pb-4 md:grid-cols-3">
                @csrf
                <x-ui.select name="department_type_id" label="{{ __('Department Type') }}" :error="$errors->first('department_type_id')">
                    <option value="">{{ __('Select a department type...') }}</option>
                    @foreach($availableTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->code ? $type->code . ' — ' : '' }}{{ $type->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="status" label="{{ __('Status') }}" :error="$errors->first('status')">
                    @foreach(['active', 'inactive', 'suspended'] as $status)
                        <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ __(ucfirst($status)) }}</option>
                    @endforeach
                </x-ui.select>
                <div class="flex items-end">
                    <x-ui.button type="submit" variant="primary">{{ __('Add Department') }}</x-ui.button>
                </div>
            </form>

            <div class="mt-4 -mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Department Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Category') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($departments as $department)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink">{{ $department->type?->code ? $department->type->code . ' — ' : '' }}{{ $department->type?->name ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $department->type?->category ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><x-ui.badge :variant="match($department->status) { 'active' => 'success', 'suspended' => 'danger', default => 'default' }">{{ ucfirst($department->status) }}</x-ui.badge></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                    <form method="POST" action="{{ route('admin.companies.departments.destroy', [$company, $department]) }}" class="inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this department?') }}')">
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
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No departments found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">{{ $departments->links() }}</div>
        </x-ui.card>
    </div>
</x-layouts.app>
