<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Tabs')"
                    component="<code>x-ui.tabs</code>, <code>x-ui.tab</code>"
                >
                    {{ __('Tabs belong here canonically because they switch between peer sections of the same page context. Cross-reference them from composite pages when surrounding layout matters.') }}
                </x-ui.catalog-section>

                <x-ui.tabs
                    :tabs="[
                        ['id' => 'overview', 'label' => __('Overview')],
                        ['id' => 'history', 'label' => __('History')],
                        ['id' => 'attachments', 'label' => __('Attachments')],
                    ]"
                    default="overview"
                    persistence="query"
                    query-key="ui-reference-tab-demo"
                >
                    <x-ui.tab id="overview">
                        <div class="rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted">
                            {{ __('Use tabs when content sections are peers and the user should remain on the same page shell while switching context.') }}
                        </div>
                    </x-ui.tab>
                    <x-ui.tab id="history">
                        <div class="rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted">
                            {{ __('Keyboard navigation, active state, and persistence behavior are part of the standard, not optional decoration.') }}
                        </div>
                    </x-ui.tab>
                    <x-ui.tab id="attachments">
                        <div class="rounded-2xl border border-border-default bg-surface-card p-4 text-sm text-muted">
                            {{ __('If sections are sequential rather than peer-level, do not use tabs. Use a workflow or stepper pattern instead.') }}
                        </div>
                    </x-ui.tab>
                </x-ui.tabs>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Page Header and Filters')"
                    component="<code>x-ui.page-header</code>, <code>x-ui.search-input</code>, <code>x-ui.select</code>"
                >
                    {{ __('The page header should remain the main orientation anchor. Filters and supporting controls sit beneath it without competing for visual priority.') }}
                </x-ui.catalog-section>

                <div class="rounded-2xl border border-border-default bg-surface-page p-4">
                    <x-ui.page-header
                        :title="__('Supplier Audits')"
                        :subtitle="__('Compact index pages should keep title, subtitle, actions, and help in one stable rhythm.')"
                        :pinnable="false"
                    >
                        <x-slot name="actions">
                            <x-ui.button variant="primary">{{ __('New Audit') }}</x-ui.button>
                        </x-slot>
                        <x-slot name="help">
                            {{ __('Use help content for local page guidance that benefits from immediate context.') }}
                        </x-slot>
                    </x-ui.page-header>

                    <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                        <x-ui.search-input id="ui-reference-nav-search" :placeholder="__('Search audits...')" />
                        <x-ui.select id="ui-reference-nav-filter">
                            <option>{{ __('All statuses') }}</option>
                            <option>{{ __('Open only') }}</option>
                            <option>{{ __('Closed only') }}</option>
                        </x-ui.select>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Pagination')"
                component="<code>$paginator-&gt;links()</code>"
            >
                {{ __('Dense result sets should paginate by default. Pagination belongs near the records it controls, not isolated from the table.') }}
            </x-ui.catalog-section>

            <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                {{ $demoPaginator->onEachSide(1)->links() }}
            </div>
        </div>
    </x-ui.card>
</div>
