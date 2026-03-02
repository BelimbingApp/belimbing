<x-layouts.app :title="__('Decision Logs')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Decision Logs')" :subtitle="__('Authorization decision audit trail')" />

        <x-ui.card>
            <div id="decision-log-filters" class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        name="search"
                        value="{{ $search }}"
                        placeholder="{{ __('Search by capability, reason, actor, or resource...') }}"
                        hx-get="{{ route('admin.authz.decision-logs.index.search') }}"
                        hx-trigger="input changed delay:300ms"
                        hx-target="#decision-logs-list"
                        hx-include="#decision-log-filters"
                        hx-swap="innerHTML"
                        hx-push-url="false"
                    />
                </div>
                <x-ui.select
                    name="filter_result"
                    hx-get="{{ route('admin.authz.decision-logs.index.search') }}"
                    hx-trigger="change"
                    hx-target="#decision-logs-list"
                    hx-include="#decision-log-filters"
                    hx-swap="innerHTML"
                    hx-push-url="false"
                >
                    <option value="">{{ __('All Results') }}</option>
                    <option value="allowed" @selected($filterResult === 'allowed')>{{ __('Allowed') }}</option>
                    <option value="denied" @selected($filterResult === 'denied')>{{ __('Denied') }}</option>
                </x-ui.select>
            </div>

            <div id="decision-logs-list">
                @include('admin.authz.decision-logs.partials.table', ['logs' => $logs])
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
