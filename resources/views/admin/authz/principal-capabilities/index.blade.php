<x-layouts.app :title="__('Principal Capabilities')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Principal Capabilities')" :subtitle="__('Per-user capability overrides — allow or deny specific capabilities outside of role assignments')" />

        <x-ui.card>
            <div class="mb-2">
                <x-ui.search-input
                    name="search"
                    value="{{ $search }}"
                    placeholder="{{ __('Search by capability, name, or email...') }}"
                    hx-get="{{ route('admin.authz.principal-capabilities.index.search') }}"
                    hx-trigger="input changed delay:300ms"
                    hx-target="#principal-capabilities-list"
                    hx-include="this"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                />
            </div>

            <div id="principal-capabilities-list">
                @include('admin.authz.principal-capabilities.partials.table', ['capabilities' => $capabilities])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
