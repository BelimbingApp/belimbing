<x-layouts.app :title="__('Capabilities')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Capabilities')" :subtitle="__('All registered capability keys and their source modules')" />

        <x-ui.card>
            <div id="capability-filters" class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        name="search"
                        value="{{ $search }}"
                        placeholder="{{ __('Search by capability or module...') }}"
                        hx-get="{{ route('admin.authz.capabilities.index.search') }}"
                        hx-trigger="input changed delay:300ms"
                        hx-target="#capabilities-list"
                        hx-include="#capability-filters"
                        hx-swap="innerHTML"
                        hx-push-url="false"
                    />
                </div>
                <x-ui.select
                    name="filter_domain"
                    hx-get="{{ route('admin.authz.capabilities.index.search') }}"
                    hx-trigger="change"
                    hx-target="#capabilities-list"
                    hx-include="#capability-filters"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                >
                    <option value="">{{ __('All Domains') }}</option>
                    @foreach ($domains as $domain)
                        <option value="{{ $domain }}" @selected($filterDomain === $domain)>{{ $domain }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            <div id="capabilities-list">
                @include('admin.authz.capabilities.partials.table', ['capabilities' => $capabilities])
            </div>

            <div class="mt-2 text-xs text-muted">
                {{ trans_choice(':count capability|:count capabilities', $capabilities->count(), ['count' => $capabilities->count()]) }}
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
