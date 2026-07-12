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
                :title="__('Pagination controls')"
                component="<code>$paginator-&gt;links()</code>"
            >
                {{ __('Dense result sets should paginate by default. Pagination belongs near the records it controls, not isolated from the table.') }}
            </x-ui.catalog-section>

            <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                {{ $demoPaginator->onEachSide(1)->links() }}
            </div>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Links')"
                component="<code>x-ui.link</code>"
            >
                {{ __('Links signal context change through a closed icon vocabulary: one behavior, one glyph. The glyph carries the verb (new tab, external, download), so the link text just names the destination. Callers pass a kind — never hand-written target, rel, or affordance icons. Things that move you are links; things that change data are buttons.') }}
            </x-ui.catalog-section>

            <div id="ui-reference-links" class="scroll-mt-4 rounded-2xl border border-border-default bg-surface-card p-4">
                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('In-app, same tab (default)') }}</dt>
                        <dd><x-ui.link href="{{ route('dashboard') }}">{{ __('Dashboard') }}</x-ui.link></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('In-app, forced new tab') }}</dt>
                        <dd><x-ui.link kind="new-tab" href="{{ route('admin.system.ui-reference.index') }}" :title="__('Open the UI reference in a new tab')">{{ __('UI reference') }}</x-ui.link></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('In-page section anchor') }}</dt>
                        <dd><x-ui.link kind="anchor" href="#ui-reference-links">{{ __('This section') }}</x-ui.link></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('External site (leaves BLB)') }}</dt>
                        <dd><x-ui.link kind="external" href="https://github.com/belimbingapp/belimbing" :title="__('Open the Belimbing repository — leaves BLB')">{{ __('Belimbing on GitHub') }}</x-ui.link></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('Download a file') }}</dt>
                        <dd><x-ui.link kind="download" href="data:text/csv;charset=utf-8,Name%2CRole%0AAda%2CEngineer%0A">{{ __('Sample CSV') }}</x-ui.link></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('Mutation (is a button)') }}</dt>
                        <dd>
                            <x-ui.icon-action
                                icon="heroicon-o-trash"
                                :label="__('Delete record')"
                                type="button"
                            />
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('Compact open (card/widget header)') }}</dt>
                        <dd>
                            <x-ui.icon-action
                                icon="heroicon-m-arrow-right"
                                :label="__('Open Dashboard')"
                                :href="route('dashboard')"
                                wire:navigate
                            />
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-muted">{{ __('Two or more related links') }}</dt>
                        <dd>
                            <x-ui.link-group>
                                <x-ui.link kind="new-tab" href="{{ route('admin.ai.task-models') }}">{{ __('AI Task Models') }}</x-ui.link>
                                <x-ui.link kind="new-tab" href="{{ route('admin.system.schedule.index') }}">{{ __('System Schedule') }}</x-ui.link>
                            </x-ui.link-group>
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 border-t border-border-default pt-3 text-xs text-muted">
                    {{ __('External and forced-new-tab share the box-arrow — the difference is rel, which the component owns. Copy uses the clipboard glyph, never the box-arrow. Trailing icons say what happens; leading icons say what kind of thing. The compact open action is the one exception to internal links carrying no glyph: it exists for tight card headers where the heading already names the destination — everywhere else, in-app navigation stays a plain x-ui.link. Two or more links side by side need x-ui.link-group — without its divider they read as one continuous phrase, not separate destinations. Button weight in a page-header actions slot is reserved for mutation and the one primary forward CTA; a secondary-variant button styled next to a real button looks equally clickable-to-do-something, so peer navigation (Settings, a related page) stays a plain link instead.') }}
                </p>
            </div>
        </div>
    </x-ui.card>
</div>
