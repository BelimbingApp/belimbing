<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-3">
        <x-ui.card>
            <div class="space-y-3">
                <x-ui.catalog-section
                    :title="__('Status Badges')"
                    component="<code>x-ui.badge</code>"
                />
                <div class="flex flex-wrap gap-2">
                    <x-ui.badge>{{ __('Default') }}</x-ui.badge>
                    <x-ui.badge variant="info">{{ __('Info') }}</x-ui.badge>
                    <x-ui.badge variant="success">{{ __('Success') }}</x-ui.badge>
                    <x-ui.badge variant="warning">{{ __('Warning') }}</x-ui.badge>
                    <x-ui.badge variant="danger">{{ __('Danger') }}</x-ui.badge>
                    <x-ui.badge variant="accent">{{ __('Accent') }}</x-ui.badge>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-3">
                <x-ui.catalog-section
                    :title="__('Metadata Block')"
                    component="<code>x-ui.datetime</code>, <code>x-ui.badge</code>"
                />
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Owner') }}</dt>
                        <dd class="text-ink">{{ __('Operations') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Updated') }}</dt>
                        <dd class="text-ink tabular-nums"><x-ui.datetime value="2026-04-23 10:30:00" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Status') }}</dt>
                        <dd><x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge></dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-3">
                <x-ui.catalog-section
                    :title="__('Dense Summary Card')"
                    component="<code>x-ui.card</code>"
                />
                <p class="text-sm text-muted">{{ __('Cards should support dense operational detail without collapsing into a wall of equal-weight text.') }}</p>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted">{{ __('Queued jobs') }}</span>
                    <span class="tabular-nums text-ink">18</span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="text-muted">{{ __('Open reviews') }}</span>
                    <span class="tabular-nums text-ink">6</span>
                </div>
            </div>
        </x-ui.card>
    </div>

    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Table Pattern')"
                component="<code>x-ui.datetime</code>, <code>x-ui.badge</code>, <code>x-ui.icon-action</code>"
            >
                {{ __('Tables should preserve compact rhythm, clear row hover, tabular values, and aligned supporting actions.') }}
            </x-ui.catalog-section>

            <div class="overflow-x-auto rounded-2xl border border-border-default">
                <table class="min-w-full divide-y divide-border-default">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Workflow') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Owner') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Updated') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @foreach ($tableRows as $row)
                            <tr class="hover:bg-surface-subtle/60 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ __($row['name']) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __($row['owner']) }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm">
                                    <x-ui.badge :variant="match ($row['status']) {
                                        'Active' => 'success',
                                        'Queued' => 'warning',
                                        default => 'default',
                                    }">
                                        {{ __($row['status']) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">
                                    <x-ui.datetime :value="$row['updated_at']" />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    <x-ui.icon-action-group>
                                        <x-ui.icon-action icon="heroicon-o-eye" :label="__('View')" />
                                        <x-ui.icon-action icon="heroicon-o-document-text" :label="__('Edit')" />
                                    </x-ui.icon-action-group>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-ui.card>
</div>
