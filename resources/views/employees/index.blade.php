<x-layouts.app :title="__('Employee Management')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Employee Management')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('admin.employees.create') }}">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Create Employee') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div id="employees-filters" class="mb-4 flex flex-col gap-4 sm:flex-row">
                <div class="flex-1">
                    <x-ui.search-input
                        name="search"
                        value="{{ $search }}"
                        placeholder="{{ __('Search by name, employee number, email, designation, or job description...') }}"
                        hx-get="{{ route('admin.employees.index.search') }}"
                        hx-trigger="input changed delay:300ms"
                        hx-target="#employees-list"
                        hx-include="#employees-filters"
                        hx-swap="innerHTML"
                        hx-push-url="false"
                    />
                </div>

                <x-ui.select
                    id="employees-type-filter"
                    name="type_filter"
                    hx-get="{{ route('admin.employees.index.search') }}"
                    hx-trigger="change"
                    hx-target="#employees-list"
                    hx-include="#employees-filters"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                >
                    <option value="all" @selected($typeFilter === 'all')>{{ __('All') }}</option>
                    <option value="human" @selected($typeFilter === 'human')>{{ __('Human only') }}</option>
                    <option value="digital_worker" @selected($typeFilter === 'digital_worker')>{{ __('Digital Worker only') }}</option>
                </x-ui.select>
            </div>

            <div id="employees-list">
                @include('employees.partials.table', ['employees' => $employees])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
