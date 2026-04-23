<div class="space-y-section-gap">
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Index Page Assembly')"
                component="<code>x-ui.page-header</code>, <code>x-ui.search-input</code>, <code>x-ui.select</code>, <code>x-ui.badge</code>, <code>x-ui.icon-action</code>"
            >
                {{ __('Composite pages are where drift usually appears. These patterns show how primitives should come together on real admin screens.') }}
            </x-ui.catalog-section>

            <div class="rounded-2xl border border-border-default bg-surface-page p-4">
                <x-ui.page-header
                    :title="__('Providers')"
                    :subtitle="__('Manage external integrations and review operational status without leaving the admin shell.')"
                    :pinnable="false"
                >
                    <x-slot name="actions">
                        <x-ui.button variant="ghost">{{ __('Export') }}</x-ui.button>
                        <x-ui.button variant="primary">{{ __('Add Provider') }}</x-ui.button>
                    </x-slot>
                </x-ui.page-header>

                <div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                    <x-ui.search-input id="ui-reference-composite-search" :placeholder="__('Search providers...')" />
                    <x-ui.select id="ui-reference-composite-filter">
                        <option>{{ __('All statuses') }}</option>
                        <option>{{ __('Configured') }}</option>
                        <option>{{ __('Needs review') }}</option>
                    </x-ui.select>
                </div>

                <div class="mt-4 overflow-x-auto rounded-2xl border border-border-default">
                    <table class="min-w-full divide-y divide-border-default">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Provider') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('State') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            <tr class="hover:bg-surface-subtle/60">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ __('OpenAI') }}</td>
                                <td class="px-table-cell-x py-table-cell-y"><x-ui.badge variant="success">{{ __('Configured') }}</x-ui.badge></td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action icon="heroicon-o-eye" :label="__('View')" />
                                        <x-ui.icon-action icon="heroicon-o-document-text" :label="__('Edit')" />
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                            <tr class="hover:bg-surface-subtle/60">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ __('GitHub Copilot') }}</td>
                                <td class="px-table-cell-x py-table-cell-y"><x-ui.badge variant="warning">{{ __('Needs review') }}</x-ui.badge></td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action icon="heroicon-o-eye" :label="__('View')" />
                                        <x-ui.icon-action icon="heroicon-o-document-text" :label="__('Edit')" />
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Form Page Assembly')"
                    component="<code>x-ui.input</code>, <code>x-ui.combobox</code>, <code>x-ui.textarea</code>, <code>x-ui.button</code>"
                >
                    {{ __('Forms should keep labels, help text, validation, and actions in one calm vertical rhythm.') }}
                </x-ui.catalog-section>

                <x-ui.input id="ui-reference-form-name" :label="__('Reference Name')" :placeholder="__('Short and specific')" />
                <x-ui.combobox
                    id="ui-reference-form-owner"
                    :label="__('Owner Team')"
                    :options="[
                        ['value' => 'platform', 'label' => __('Platform')],
                        ['value' => 'operations', 'label' => __('Operations')],
                        ['value' => 'quality', 'label' => __('Quality')],
                    ]"
                    :placeholder="__('Search team...')"
                />
                <x-ui.textarea id="ui-reference-form-notes" :label="__('Notes')" rows="4">{{ __('Use compact supporting copy so the field still reads as part of the form rather than as a document editor.') }}</x-ui.textarea>

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="ghost">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="primary">{{ __('Save Reference') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Feedback Flow')"
                    component="<code>x-ui.alert</code>, <code>x-ui.input</code>"
                >
                    {{ __('A typical flow combines in-flow guidance, inline validation, and transient flash feedback after action completion.') }}
                </x-ui.catalog-section>

                <x-ui.alert variant="info">
                    {{ __('Use inline guidance before the user acts, not only after an error occurs.') }}
                </x-ui.alert>

                <x-ui.input
                    id="ui-reference-form-error"
                    :label="__('Slug')"
                    :value="__('needs spaces removed')"
                    :error="__('Use lowercase letters, numbers, and hyphens only.')"
                />

                <div class="rounded-2xl border border-dashed border-border-default bg-surface-subtle p-4 text-xs text-muted">
                    {{ __('After save, prefer a transient flash notification rather than leaving stale success copy in the page body.') }}
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
