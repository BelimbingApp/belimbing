<div>
    <x-slot name="title">{{ __('Tenant Audit') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Tenant Audit')"
            :subtitle="__('Live read-only view of blb:domain-routes --audit and blb:module-ownership. This page does not repair findings.')"
        />

        <x-ui.card>
            <h2 class="mb-3 text-sm font-semibold text-ink">{{ __('Domain route middleware') }}</h2>

            <x-ui.table container="flush" :caption="__('Routes missing tenant assertion and/or authorization middleware')">
                <x-slot name="head">
                    <tr>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Route') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('URI') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Missing') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Module path') }}</th>
                    </tr>
                </x-slot>

                @forelse ($routeRows as $row)
                    <tr
                        wire:key="tenant-audit-route-{{ $row['name'] ?? $row['uri'] }}"
                        @if ($row['severity'] === 'danger') data-audit-severity="danger" @endif
                    >
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <x-ui.badge variant="danger">{{ __('Uncovered') }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">
                            @if (filled($row['name']) && \Illuminate\Support\Facades\Route::has($row['name']))
                                <x-ui.link kind="internal" href="{{ route($row['name']) }}">{{ $row['name'] }}</x-ui.link>
                            @elseif (filled($row['name']))
                                {{ $row['name'] }}
                            @else
                                <span class="text-muted">{{ __('(unnamed)') }}</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">{{ $row['uri'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ implode(', ', $row['missing']) }}</td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">{{ $row['module_path'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('Domain route middleware audit passed — no uncovered routes.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-3 text-sm font-semibold text-ink">{{ __('Module ownership') }}</h2>

            @if ($ownership['ok'])
                <p class="text-sm text-muted">{{ __('No Domain module ownership collisions.') }}</p>
            @else
                <x-ui.table container="flush" :caption="__('Ownership collisions across Domain modules')">
                    <x-slot name="head">
                        <tr>
                            <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Refusal') }}</th>
                        </tr>
                    </x-slot>

                    @foreach ($ownership['refusals'] as $refusal)
                        <tr wire:key="tenant-audit-ownership-{{ md5($refusal) }}" data-audit-severity="danger">
                            <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                <x-ui.badge variant="danger">{{ __('Collision') }}</x-ui.badge>
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $refusal }}</td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif

            @if ($ownership['modules'] !== [])
                <div class="mt-4">
                    <h3 class="mb-2 text-xs font-medium uppercase tracking-wider text-muted">{{ __('Scanned Domain modules') }}</h3>
                    <ul class="space-y-1">
                        @foreach ($ownership['modules'] as $module)
                            <li class="font-mono text-xs text-muted" wire:key="tenant-audit-module-{{ $module['id'] }}">
                                <span class="text-ink">{{ $module['id'] }}</span>
                                @if (filled($module['path']))
                                    — {{ $module['path'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card>
            <h2 class="mb-3 text-sm font-semibold text-ink">{{ __('Recent tenant-context misses') }}</h2>
            <p class="mb-3 text-sm text-muted">{{ __('Last :count RequireTenantContext refusals with the route name and the resolver that returned null. The HTTP 404 is unchanged.', ['count' => 50]) }}</p>

            <x-ui.table container="flush" :caption="__('Recent tenant-context misses')">
                <x-slot name="head">
                    <tr>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('When') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Route') }}</th>
                        <th scope="col" class="px-table-cell-x py-table-cell-y text-left text-xs font-medium text-muted uppercase tracking-wider">{{ __('Resolver') }}</th>
                    </tr>
                </x-slot>

                @forelse ($missRows as $miss)
                    <tr wire:key="tenant-audit-miss-{{ $miss['at'] }}-{{ $loop->index }}" data-tenant-miss="1">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">{{ $miss['at'] }}</td>
                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $miss['route'] ?? __('(unnamed)') }}</td>
                        <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $miss['resolver'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No tenant-context misses recorded yet.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>
        </x-ui.card>
    </div>
</div>
