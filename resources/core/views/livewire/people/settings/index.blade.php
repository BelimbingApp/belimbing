<div>
    <x-slot name="title">{{ __('People Settings') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('People Settings')" :subtitle="__('Reference data, employee access, migration imports, and operations controls for People workflows.')">
            <x-slot name="help">
                {{ __('Names here use BLB vocabulary. iPayroll labels are retained only as source metadata for migration and audit.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <x-ui.tabs
                    :tabs="$tabs"
                    :default="$tab"
                    size="sm"
                    persistence="none"
                    wire-action="setTab"
                    class="w-full lg:w-auto"
                >
                    @foreach ($tabs as $tabItem)
                        <x-ui.tab :id="$tabItem['id']" />
                    @endforeach
                </x-ui.tabs>

                @if ($tab === 'reference-data')
                    <div class="w-full lg:w-80">
                        <x-ui.search-input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search code, name, or source label...') }}" />
                    </div>
                @endif
            </div>

            @if ($tab === 'reference-data')
                <div class="space-y-4">
                    @if ($canManage)
                        <form wire:submit="createReferenceEntry" class="grid gap-3 rounded-xl border border-border-default bg-surface-subtle p-4 lg:grid-cols-5">
                            <label class="text-sm">
                                <span class="mb-1 block text-xs font-medium text-muted">{{ __('Type') }}</span>
                                <select wire:model="referenceType" class="w-full rounded-lg border-border-default bg-surface-card text-sm">
                                    @foreach ($referenceTypes as $type => $label)
                                        <option value="{{ $type }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm">
                                <span class="mb-1 block text-xs font-medium text-muted">{{ __('Code') }}</span>
                                <input wire:model="entryCode" class="w-full rounded-lg border-border-default bg-surface-card text-sm" />
                            </label>
                            <label class="text-sm lg:col-span-2">
                                <span class="mb-1 block text-xs font-medium text-muted">{{ __('Name') }}</span>
                                <input wire:model="entryName" class="w-full rounded-lg border-border-default bg-surface-card text-sm" />
                            </label>
                            <label class="text-sm">
                                <span class="mb-1 block text-xs font-medium text-muted">{{ __('Level') }}</span>
                                <input wire:model="entryLevel" class="w-full rounded-lg border-border-default bg-surface-card text-sm" placeholder="{{ __('Optional') }}" />
                            </label>
                            <label class="text-sm lg:col-span-4">
                                <span class="mb-1 block text-xs font-medium text-muted">{{ __('Source label') }}</span>
                                <input wire:model="entrySourceLabel" class="w-full rounded-lg border-border-default bg-surface-card text-sm" placeholder="{{ __('Optional migration/source label') }}" />
                            </label>
                            <div class="flex items-end justify-end">
                                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                            </div>
                        </form>
                    @endif

                    <div class="overflow-x-auto -mx-card-inner px-card-inner">
                        <table class="min-w-full divide-y divide-border-default text-sm">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Source') }}</th>
                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="bg-surface-card divide-y divide-border-default">
                                @forelse ($referenceEntries as $entry)
                                    <tr wire:key="reference-entry-{{ $entry->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">{{ $referenceTypes[$entry->type] ?? $entry->type }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs">{{ $entry->code }}</td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="font-medium text-ink">{{ $entry->name }}</div>
                                            @if ($entry->level)
                                                <div class="text-xs text-muted">{{ __('Level: :level', ['level' => $entry->level]) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $entry->source_label ?? '-' }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap"><x-ui.badge>{{ __(ucfirst($entry->status)) }}</x-ui.badge></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No reference data yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $referenceEntries->links() }}
                </div>
            @elseif ($tab === 'portal-access')
                <div class="space-y-3">
                    @forelse ($portalAccesses as $access)
                        <div class="rounded-xl border border-border-default p-4" wire:key="portal-access-{{ $access->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $access->display_name }}</div>
                                    <div class="text-xs text-muted">{{ $access->employee?->employee_number }} · {{ $access->login_identifier ?? '-' }} · {{ $access->email ?? '-' }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($access->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No employee portal access records yet.') }}</p>
                    @endforelse
                </div>
            @elseif ($tab === 'requests')
                <div class="space-y-3">
                    @forelse ($profileRequests as $request)
                        <div class="rounded-xl border border-border-default p-4" wire:key="profile-request-{{ $request->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $request->employee?->displayName() }}</div>
                                    <div class="text-xs text-muted">{{ $request->request_type }} · {{ $request->submitted_at?->format('Y-m-d H:i') }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($request->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No profile change requests yet.') }}</p>
                    @endforelse
                </div>
            @elseif ($tab === 'restricted')
                @if (! $canViewSensitive)
                    <x-ui.alert variant="warning">{{ __('Restricted-person records require sensitive People Settings access.') }}</x-ui.alert>
                @else
                    <div class="space-y-3">
                        @forelse ($restrictedEntries as $entry)
                            <div class="rounded-xl border border-border-default p-4" wire:key="restricted-entry-{{ $entry->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-ink">{{ $entry->person_name ?? __('Unnamed person') }}</div>
                                        <div class="text-xs text-muted">{{ $entry->document_type ?? '-' }} · {{ $entry->document_number ?? '-' }}</div>
                                    </div>
                                    <x-ui.badge>{{ __(ucfirst($entry->status)) }}</x-ui.badge>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No restricted-person entries yet.') }}</p>
                        @endforelse
                    </div>
                @endif
            @elseif ($tab === 'imports')
                <div class="space-y-3">
                    @if ($canManage)
                        <x-ui.button variant="secondary" wire:click="dryRunSampleImport">{{ __('Record empty dry-run import') }}</x-ui.button>
                    @endif
                    @forelse ($importJobs as $job)
                        <div class="rounded-xl border border-border-default p-4" wire:key="import-job-{{ $job->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $job->source_label }} → {{ $referenceTypes[$job->target_type] ?? $job->target_type }}</div>
                                    <div class="text-xs text-muted">{{ __('Rows: :rows, errors: :errors', ['rows' => $job->summary['total_rows'] ?? 0, 'errors' => $job->summary['error_rows'] ?? 0]) }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($job->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No import jobs yet.') }}</p>
                    @endforelse
                </div>
            @else
                <div class="space-y-3">
                    @forelse ($notificationLogs as $log)
                        <div class="rounded-xl border border-border-default p-4" wire:key="notification-log-{{ $log->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-ink">{{ $log->subject ?? __('Notification') }}</div>
                                    <div class="text-xs text-muted">{{ $log->channel }} · {{ $log->recipient ?? '-' }}</div>
                                </div>
                                <x-ui.badge>{{ __(ucfirst($log->status)) }}</x-ui.badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('No notification delivery logs yet.') }}</p>
                    @endforelse
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
