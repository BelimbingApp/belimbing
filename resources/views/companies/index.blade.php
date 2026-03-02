<x-layouts.app :title="__('Company Management')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Company Management')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('admin.companies.create') }}">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Create Company') }}
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
            <div class="mb-2">
                <x-ui.search-input
                    name="search"
                    value="{{ $search }}"
                    placeholder="{{ __('Search by company name, code, legal name, email, or jurisdiction...') }}"
                    hx-get="{{ route('admin.companies.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#companies-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            @include('companies.partials.table', ['companies' => $companies])
        </x-ui.card>
    </div>
</x-layouts.app>
