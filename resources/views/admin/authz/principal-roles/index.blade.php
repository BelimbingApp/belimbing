<x-layouts.app :title="__('Principal Roles')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Principal Roles')" :subtitle="__('User and principal role assignments')" />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    name="search"
                    value="{{ $search }}"
                    placeholder="{{ __('Search by name, email, or role...') }}"
                    hx-get="{{ route('admin.authz.principal-roles.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#principal-roles-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            <div id="principal-roles-list">
                @include('admin.authz.principal-roles.partials.table', ['assignments' => $assignments])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
