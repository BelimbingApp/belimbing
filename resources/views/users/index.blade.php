<x-layouts.app :title="__('User Management')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('User Management')">
            <x-slot name="actions">
                <x-ui.button variant="primary" as="a" href="{{ route('admin.users.create') }}">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Create User') }}
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
                    placeholder="{{ __('Search by name or email...') }}"
                    hx-get="{{ route('admin.users.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#users-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            <div id="users-list">
                @include('users.partials.table', ['users' => $users, 'canDelete' => $canDelete, 'search' => $search])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
