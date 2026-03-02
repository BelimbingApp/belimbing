<x-layouts.app :title="__('Role Management')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Role Management')" :subtitle="__('Manage roles and their capabilities')">
            @if ($canCreate)
                <x-slot name="actions">
                    <x-ui.button variant="primary" as="a" href="{{ route('admin.roles.create') }}">
                        <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                        {{ __('Create Role') }}
                    </x-ui.button>
                </x-slot>
            @endif
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
                    placeholder="{{ __('Search by name, code, or description...') }}"
                    hx-get="{{ route('admin.roles.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#roles-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            <div id="roles-list">
                @include('admin.roles.partials.table', ['roles' => $roles])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
