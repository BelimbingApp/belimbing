<x-layouts.app :title="__('Address Management')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Address Management')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('admin.addresses.create') }}">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Create Address') }}
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
                    placeholder="{{ __('Search by label, address line, city, postcode, or country code...') }}"
                    hx-get="{{ route('admin.addresses.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#addresses-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            <div id="addresses-list">
                @include('addresses.partials.table', ['addresses' => $addresses, 'search' => $search])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
