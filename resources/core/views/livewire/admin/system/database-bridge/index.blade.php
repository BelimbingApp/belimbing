<?php

use App\Base\Database\Livewire\Bridge\Index;

/** @var Index $this */

$badgeVariant = static fn (string $status): string => match ($status) {
    'applied', 'consumed', 'ready', 'verified' => 'success',
    'conflicts', 'expired', 'revoked' => 'warning',
    'failed', 'apply_failed' => 'danger',
    default => 'info',
};
$shortHash = static fn (?string $hash): string => $hash === null ? '—' : substr($hash, 0, 12).'…';
$formatBytes = static fn (int $bytes): string => match (true) {
    $bytes >= 1_048_576 => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->format($bytes / 1_048_576, 1, 1).' MB',
    $bytes >= 1024 => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->format($bytes / 1024, 1, 1).' KB',
    default => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($bytes).' B',
};
$selectedScope = collect($scopes)->firstWhere('name', $scopeName);
?>

<div>
    <x-slot name="title">{{ __('Data Bridge') }}</x-slot>

    <x-ui.page-header
        :title="__('Data Bridge')"
        :subtitle="__('Select registered tables and stream one reviewed package using a target-issued, one-time receive key.')"
    >
        <x-slot name="actions">
            <div class="flex flex-wrap items-center justify-end gap-2 text-sm text-muted">
                <x-ui.badge :variant="$instance->role->value === 'production' ? 'warning' : 'info'">
                    {{ ucfirst($instance->role->value) }}
                </x-ui.badge>
                <span class="font-mono text-xs" title="{{ $instance->id }}">{{ $instance->name }}</span>
                @if($canManageSettings)
                    <x-ui.button
                        as="a"
                        variant="ghost"
                        size="sm"
                        :href="route('admin.system.database-bridge.settings')"
                        wire:navigate
                    >
                        <x-icon name="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                        {{ __('Settings') }}
                    </x-ui.button>
                @endif
            </div>
        </x-slot>
    </x-ui.page-header>

    <div class="mt-4 space-y-section-gap">
        @if($statusMessage)
            <x-ui.alert :variant="$statusVariant ?? 'info'">
                {{ $statusMessage }}
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-ui.tabs
                :tabs="[
                    ['id' => 'export', 'label' => __('Export'), 'icon' => 'heroicon-o-arrow-up-tray'],
                    ['id' => 'incoming', 'label' => __('Incoming'), 'icon' => 'heroicon-o-inbox-arrow-down'],
                    ['id' => 'history', 'label' => __('History'), 'icon' => 'heroicon-o-clock'],
                    ['id' => 'receive', 'label' => __('Receive'), 'icon' => 'heroicon-o-key'],
                    ['id' => 'diagnostics', 'label' => __('Diagnostics'), 'icon' => 'heroicon-o-wrench-screwdriver'],
                ]"
                default="export"
            >
                <x-ui.tab id="export">
                    <div class="space-y-6">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Choose what to move') }}</h2>
                            <p class="mt-1 text-sm text-muted">
                                {{ __('Base discovers modules and tables from the database registry, then reads primary keys, unique constraints, and foreign keys from the live schema. Modules implement nothing for Data Bridge.') }}
                            </p>
                        </div>

                        @if($scopes === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No registered table scopes are available') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Run database registry reconciliation after migrations, then return here.') }}</p>
                            </div>
                        @else
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
                                <div class="space-y-4">
                                    <x-ui.select
                                        id="bridge-scope"
                                        :label="__('Module scope')"
                                        wire:model.live="scopeName"
                                        :disabled="$targetId !== ''"
                                    >
                                        @foreach($scopes as $scope)
                                            <option value="{{ $scope['name'] }}">{{ $scope['label'] }} · {{ $scope['module_path'] }}</option>
                                        @endforeach
                                    </x-ui.select>

                                    @if($selectedScope)
                                        <div wire:key="scope-{{ $selectedScope['name'] }}" class="rounded-xl border border-border-default bg-surface-subtle">
                                            <div class="flex flex-col gap-3 border-b border-border-default p-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p class="text-sm font-medium text-ink">{{ __('Registered tables') }}</p>
                                                    <p class="mt-0.5 text-xs text-muted">{{ __('Select the entire module for a complete promotion, or choose an exact table subset for another export.') }}</p>
                                                </div>
                                                <x-ui.button variant="ghost" size="sm" wire:click="selectEntireScope">
                                                    {{ __('Select entire module') }}
                                                </x-ui.button>
                                            </div>
                                            <div class="grid gap-px bg-border-default sm:grid-cols-2">
                                                @foreach($selectedScope['tables'] as $table)
                                                    <div class="bg-surface-card p-3" wire:key="scope-table-{{ $table['name'] }}">
                                                        <x-ui.checkbox
                                                            id="bridge-table-{{ $loop->index }}"
                                                            wire:model.live="selectedTables"
                                                            value="{{ $table['name'] }}"
                                                            :label="$table['name']"
                                                            :disabled="! $table['bridgeable']"
                                                        />
                                                        <p class="mt-1 pl-6 text-xs text-muted">
                                                            @if($table['bridgeable'])
                                                                {{ __('Primary key: :key · :count foreign-key reference(s)', [
                                                                    'key' => implode(', ', $table['primary_key']),
                                                                    'count' => $table['references'],
                                                                ]) }}
                                                            @else
                                                                {{ __('No primary key; generic import cannot identify rows safely.') }}
                                                            @endif
                                                        </p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                <div class="space-y-4 border-t border-border-default pt-4 lg:border-l lg:border-t-0 lg:pl-5 lg:pt-0">
                                     <div>
                                         <h3 class="text-sm font-medium text-ink">{{ __('Destination') }}</h3>
                                         <p class="mt-1 text-sm text-muted">{{ __('Paste the copy-once key generated by the target. It binds source, target, scope, limit, and expiry; the secret is never put in the package.') }}</p>
                                     </div>

                                     <x-ui.textarea
                                         id="bridge-receive-key"
                                         :label="__('One-time receive key')"
                                         :help="__('Paste the complete JSON bundle. The source keeps it only long enough to preview and send.')"
                                         rows="5"
                                         wire:model="receiveBundle"
                                         autocomplete="off"
                                     />
                                     <x-ui.button variant="control" class="w-full justify-center" wire:click="applyReceiveBundle">
                                         <x-icon name="heroicon-o-key" class="h-4 w-4" />
                                         {{ __('Use receive key') }}
                                     </x-ui.button>

                                     @if($targetId !== '')
                                         @if(count($targetEndpoints) > 1)
                                             <x-ui.select
                                                 id="bridge-receive-endpoint"
                                                 :label="__('Transport route')"
                                                 :help="__('Choose the private LAN route when it is reachable; use the Cloudflare route as the remote fallback.')"
                                                 wire:model.live="targetEndpoint"
                                             >
                                                 @foreach($targetEndpoints as $endpoint)
                                                     @php
                                                         $endpointHost = (string) parse_url($endpoint, PHP_URL_HOST);
                                                         $endpointPort = parse_url($endpoint, PHP_URL_PORT);
                                                     @endphp
                                                     <option value="{{ $endpoint }}">
                                                         {{ $endpointHost }}{{ $endpointPort ? ':'.$endpointPort : '' }}
                                                     </option>
                                                 @endforeach
                                             </x-ui.select>
                                         @endif

                                         <div class="rounded-xl bg-surface-subtle p-3 text-sm">
                                             <p class="font-medium text-ink">{{ $targetName }}</p>
                                             <p class="mt-0.5 break-all font-mono text-xs text-muted">{{ $targetId }}</p>
                                             <p class="mt-1 text-xs text-muted">{{ ucfirst($targetRole) }} · {{ $scopeName }}</p>
                                             <p class="mt-1 break-all font-mono text-xs text-muted">{{ $targetEndpoint }}</p>
                                         </div>
                                     @endif

                                    @if($canExport)
                                        <x-ui.button
                                            variant="secondary"
                                            class="w-full justify-center"
                                            wire:click="previewExport"
                                            wire:loading.attr="disabled"
                                            wire:target="previewExport,createExport"
                                        >
                                            <x-icon name="heroicon-o-document-magnifying-glass" class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="previewExport">{{ __('Preview export') }}</span>
                                            <span wire:loading wire:target="previewExport">{{ __('Reading selected tables…') }}</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if($exportPreview)
                            <div class="border-t border-border-default pt-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Reviewed export') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('Creating the package recomputes this preview and stops if any selected source row changed.') }}</p>
                                    </div>
                                    <x-ui.badge variant="info">{{ __('Conflicts rejected') }}</x-ui.badge>
                                </div>

                                <dl class="mt-4 grid gap-px overflow-hidden rounded-xl bg-border-default sm:grid-cols-3">
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tables') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ $exportPreview['counts']['tables'] }}</dd>
                                    </div>
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Records') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($exportPreview['counts']['records']) }}</dd>
                                    </div>
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Estimated size') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ $formatBytes($exportPreview['estimated_bytes']) }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-4">
                                    <x-ui.disclosure :title="__('Table manifest')">
                                        <div class="divide-y divide-border-default">
                                            @foreach($exportPreview['payloads'] as $payload)
                                                <div class="flex flex-col gap-1 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                                                    <span class="break-all font-mono text-xs text-ink">{{ $payload['table'] }}</span>
                                                    <span class="text-xs tabular-nums text-muted">{{ __(':records records · :size', [
                                                        'records' => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($payload['records']),
                                                        'size' => $formatBytes($payload['bytes']),
                                                    ]) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </x-ui.disclosure>
                                </div>

                                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="break-all font-mono text-xs text-muted" title="{{ $exportPreview['preview_sha256'] }}">
                                        {{ __('Preview SHA-256: :hash', ['hash' => $exportPreview['preview_sha256']]) }}
                                    </p>
                                    @if($canExport)
                                        <x-ui.button wire:click="createExport" wire:loading.attr="disabled" wire:target="createExport">
                                            <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="createExport">{{ __('Send package') }}</span>
                                            <span wire:loading wire:target="createExport">{{ __('Verifying and streaming…') }}</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </x-ui.tab>

                <x-ui.tab id="incoming">
                    <div class="space-y-5">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Packages received by this instance') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Receipt verifies identity, target, expiry, schema, counts, and every payload hash. It does not plan or apply data.') }}</p>
                        </div>

                        @if($incoming === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('Incoming is empty') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Issue a one-time receive key on this target, paste it into the source, and send the reviewed package when ready.') }}</p>
                            </div>
                        @else
                            <x-ui.table :caption="__('Incoming Data Bridge packages')">
                                <x-slot name="head">
                                    <tr>
                                        <x-ui.th>{{ __('Package') }}</x-ui.th>
                                        <x-ui.th>{{ __('Source and scope') }}</x-ui.th>
                                        <x-ui.th>{{ __('Received') }}</x-ui.th>
                                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                                        <x-ui.th>{{ __('Plan') }}</x-ui.th>
                                        <x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>
                                    </tr>
                                </x-slot>
                                @foreach($incoming as $receipt)
                                    <tr wire:key="receipt-{{ $receipt['id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y align-top">
                                            <p class="font-mono text-xs text-ink">{{ $receipt['package_id'] }}</p>
                                            <p class="mt-1 font-mono text-xs text-muted" title="{{ $receipt['sha256'] }}">{{ $shortHash($receipt['sha256']) }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y align-top text-sm">
                                            <p class="text-ink">{{ $receipt['source_instance_id'] }}</p>
                                            <p class="mt-0.5 font-mono text-xs text-muted">{{ $receipt['scope_name'] }}</p>
                                            <p class="mt-0.5 font-mono text-xs text-muted">{{ __('Grant :grant', ['grant' => $receipt['grant_id']]) }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y align-top whitespace-nowrap text-sm text-muted tabular-nums">
                                            <x-ui.datetime :value="$receipt['received_at']" />
                                            <p class="mt-1 text-xs">{{ __('Expires') }} <x-ui.datetime :value="$receipt['expires_at']" /></p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y align-top">
                                            <x-ui.badge :variant="$badgeVariant($receipt['status'])">{{ ucfirst($receipt['status']) }}</x-ui.badge>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y align-top text-sm">
                                            @if($receipt['plan'])
                                                <div class="flex items-center gap-2">
                                                    <x-ui.badge :variant="$badgeVariant($receipt['plan']['status'])">{{ ucfirst($receipt['plan']['status']) }}</x-ui.badge>
                                                    <span class="font-mono text-xs text-muted" title="{{ $receipt['plan']['hash'] }}">{{ $shortHash($receipt['plan']['hash']) }}</span>
                                                </div>
                                                <p class="mt-1 text-xs text-muted tabular-nums">
                                                    {{ __(':insert insert · :same unchanged · :conflict conflict', [
                                                        'insert' => $receipt['plan']['summary']['counts']['insert'],
                                                        'same' => $receipt['plan']['summary']['counts']['unchanged'],
                                                        'conflict' => $receipt['plan']['summary']['counts']['conflict'],
                                                    ]) }}
                                                </p>
                                            @else
                                                <span class="text-muted">{{ __('Not planned') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y align-top text-right">
                                            <div class="inline-flex items-center gap-1.5">
                                                @if($canPlan && $receipt['status'] !== 'applied')
                                                    <x-ui.button variant="ghost" size="sm" wire:click="planReceipt({{ $receipt['id'] }})" wire:loading.attr="disabled" wire:target="planReceipt({{ $receipt['id'] }})">
                                                        <x-icon name="heroicon-o-document-magnifying-glass" class="h-4 w-4" />
                                                        {{ $receipt['plan'] ? __('Replan') : __('Plan') }}
                                                    </x-ui.button>
                                                @endif
                                                @if($canApply && ($receipt['plan']['status'] ?? null) === 'ready')
                                                    <x-ui.button size="sm" wire:click="prepareApply({{ $receipt['plan']['id'] }})">
                                                        {{ __('Review apply') }}
                                                    </x-ui.button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    @if($applyPlanId === ($receipt['plan']['id'] ?? null))
                                        <tr wire:key="apply-{{ $receipt['plan']['id'] }}">
                                            <td colspan="6" class="px-table-cell-x py-4 bg-surface-subtle">
                                                <div class="max-w-3xl">
                                                    <h3 class="text-sm font-medium text-ink">{{ __('Confirm the exact reviewed hashes') }}</h3>
                                                    <p class="mt-1 text-sm text-muted">
                                                        {{ $instance->role->value === 'production'
                                                            ? __('A fresh verified database backup is mandatory. Apply then runs under a lock and stops if destination data changed after planning.')
                                                            : __('Apply runs under a lock and stops if destination data changed after planning.') }}
                                                    </p>
                                                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                        <x-ui.input id="bridge-apply-package-hash" :label="__('Package SHA-256')" wire:model="applyPackageHash" autocomplete="off" />
                                                        <x-ui.input id="bridge-apply-plan-hash" :label="__('Plan SHA-256')" wire:model="applyPlanHash" autocomplete="off" />
                                                    </div>
                                                    @if($instance->role->value === 'production' && $passwordConfirmationUrl)
                                                        <p class="mt-3 text-sm text-muted">
                                                            <x-ui.link kind="internal" href="{{ $passwordConfirmationUrl }}">{{ __('Confirm your password') }}</x-ui.link>
                                                            {{ __('before applying to production.') }}
                                                        </p>
                                                    @endif
                                                    <div class="mt-4 flex items-center gap-2">
                                                        <x-ui.button wire:click="applySelectedPlan" wire:loading.attr="disabled" wire:target="applySelectedPlan">
                                                            <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                                            <span wire:loading.remove wire:target="applySelectedPlan">{{ __('Apply reviewed plan') }}</span>
                                                            <span wire:loading wire:target="applySelectedPlan">{{ __('Verifying and applying…') }}</span>
                                                        </x-ui.button>
                                                        <x-ui.button variant="ghost" wire:click="cancelApply">{{ __('Cancel') }}</x-ui.button>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </x-ui.table>
                        @endif
                    </div>
                </x-ui.tab>

                <x-ui.tab id="history">
                    <div class="space-y-5">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Bridge ledger') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('The ledger keeps identities, hashes, counts, actors, and outcomes. It never copies domain payload values into audit history.') }}</p>
                        </div>

                        @if($history === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No bridge events yet') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Receive-key lifecycle, receipts, plans, applies, and verification failures appear here.') }}</p>
                            </div>
                        @else
                            <x-ui.table :caption="__('Data Bridge audit ledger')">
                                <x-slot name="head">
                                    <tr>
                                        <x-ui.th>{{ __('Event') }}</x-ui.th>
                                        <x-ui.th>{{ __('Package') }}</x-ui.th>
                                        <x-ui.th>{{ __('Source and scope') }}</x-ui.th>
                                        <x-ui.th>{{ __('Outcome') }}</x-ui.th>
                                        <x-ui.th>{{ __('Time') }}</x-ui.th>
                                    </tr>
                                </x-slot>
                                @foreach($history as $event)
                                    <tr wire:key="event-{{ $event['id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y text-sm">
                                            <x-ui.badge :variant="$badgeVariant($event['action'])">{{ str_replace('_', ' ', ucfirst($event['action'])) }}</x-ui.badge>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $event['package_id'] ?? '—' }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-sm">
                                            <p class="text-ink">{{ $event['source_instance_id'] ?? '—' }}</p>
                                            <p class="font-mono text-xs text-muted">{{ $event['scope_name'] ?? '—' }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                            @if($event['error_summary'])
                                                <span class="text-status-danger">{{ $event['error_summary'] }}</span>
                                            @elseif(isset($event['metadata']['counts']))
                                                {{ __(':records records', ['records' => array_sum($event['metadata']['counts'])]) }}
                                            @else
                                                {{ __('Recorded') }}
                                            @endif
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$event['created_at']" /></td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        @endif
                    </div>
                </x-ui.tab>

                <x-ui.tab id="receive">
                    <div class="space-y-6">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('One-time receive keys') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('The target authorizes one expected source and one registered scope. Copy the generated key to the source; receipt never grants plan or apply access.') }}</p>
                        </div>

                        @if($instance->role->value === 'development')
                            <x-ui.alert variant="info">
                                {{ __('This development instance cannot issue an upward-promotion receive key. Generate the key while logged into the staging or production target.') }}
                            </x-ui.alert>
                        @elseif($canManageReceiveGrants)
                            <div class="grid gap-4 border-b border-border-default pb-6 md:grid-cols-2">
                                <x-ui.input id="bridge-grant-source-id" :label="__('Expected source instance ID')" wire:model="grantSourceId" autocomplete="off" />
                                <x-ui.input id="bridge-grant-source-name" :label="__('Source display name')" wire:model="grantSourceName" autocomplete="off" />
                                <x-ui.select id="bridge-grant-source-role" :label="__('Expected source role')" wire:model="grantSourceRole">
                                    <option value="development">{{ __('Development') }}</option>
                                    <option value="staging">{{ __('Staging') }}</option>
                                </x-ui.select>
                                <x-ui.select id="bridge-grant-scope" :label="__('Allowed scope')" wire:model="grantScope">
                                    @foreach($scopes as $scope)
                                        <option value="{{ $scope['name'] }}">{{ $scope['label'] }} · {{ $scope['module_path'] }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="md:col-span-2">
                                    <x-ui.button wire:click="issueReceiveGrant">
                                        <x-icon name="heroicon-o-key" class="h-4 w-4" />
                                        {{ __('Generate one-time key') }}
                                    </x-ui.button>
                                </div>
                            </div>
                        @endif

                        @if($issuedReceiveBundle)
                            <x-ui.alert variant="warning">
                                <p class="font-medium">{{ __('Copy this key now') }}</p>
                                <p class="mt-1">{{ __('Only its hash is stored. Closing or refreshing this page permanently removes the plaintext display.') }}</p>
                                <div class="mt-3">
                                    <x-ui.textarea id="bridge-issued-receive-key" rows="6" readonly>{{ $issuedReceiveBundle }}</x-ui.textarea>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <x-ui.button
                                        variant="control"
                                        size="sm"
                                        x-on:click="navigator.clipboard.writeText(@js($issuedReceiveBundle))"
                                    >
                                        <x-icon name="heroicon-o-clipboard" class="h-4 w-4" />
                                        {{ __('Copy key') }}
                                    </x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" wire:click="clearIssuedReceiveBundle">{{ __('Hide permanently') }}</x-ui.button>
                                </div>
                            </x-ui.alert>
                        @endif

                        @if($grants === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No receive keys issued') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('This target rejects every Data Bridge stream until an authorized user issues a short-lived key.') }}</p>
                            </div>
                        @else
                            <x-ui.table :caption="__('One-time Data Bridge receive grants')">
                                <x-slot name="head">
                                    <tr>
                                        <x-ui.th>{{ __('Key and source') }}</x-ui.th>
                                        <x-ui.th>{{ __('Scope') }}</x-ui.th>
                                        <x-ui.th>{{ __('Expires') }}</x-ui.th>
                                        <x-ui.th>{{ __('Status') }}</x-ui.th>
                                        @if($canManageReceiveGrants)<x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>@endif
                                    </tr>
                                </x-slot>
                                @foreach($grants as $grant)
                                    <tr wire:key="grant-{{ $grant['id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y text-sm">
                                            <p class="font-mono text-xs text-ink">{{ $grant['grant_id'] }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ $grant['source_instance_id'] }} · {{ ucfirst($grant['source_role']) }}</p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y break-all font-mono text-xs text-muted">{{ $grant['scope_name'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"><x-ui.datetime :value="$grant['expires_at']" /></td>
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <x-ui.badge :variant="$badgeVariant($grant['status'])">{{ ucfirst($grant['status']) }}</x-ui.badge>
                                        </td>
                                        @if($canManageReceiveGrants)
                                            <td class="px-table-cell-x py-table-cell-y text-right">
                                                @if($grant['status'] === 'issued')
                                                    <x-ui.button variant="ghost" size="sm" wire:click="revokeReceiveGrant({{ $grant['id'] }})" wire:confirm="{{ __('Revoke this one-time receive key? Any future send using it will be refused.') }}">
                                                        {{ __('Revoke') }}
                                                    </x-ui.button>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        @endif
                    </div>
                </x-ui.tab>

                <x-ui.tab id="diagnostics">
                    <div class="space-y-5">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Diagnostic row capture') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Byte-exact captures reproduce unusual development data. They are separate from bulk Data Export and are categorically refused by staging and production importers.') }}</p>
                        </div>

                        <x-ui.alert variant="info">
                            {{ __('Packages are stored on the private :disk disk under :prefix. Identified secrets are redacted, but remaining values may still be sensitive.', ['disk' => $diskName, 'prefix' => $pathPrefix]) }}
                        </x-ui.alert>

                        @if($diagnosticPackages === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No diagnostic captures') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Open Database Tables, select a few rows, and preview their Data Bridge diagnostic package.') }}</p>
                                <div class="mt-3">
                                    <x-ui.button as="a" size="sm" href="{{ route('admin.system.database-tables.index') }}" wire:navigate>
                                        <x-icon name="heroicon-o-table-cells" class="h-4 w-4" />
                                        {{ __('Browse tables') }}
                                    </x-ui.button>
                                </div>
                            </div>
                        @else
                            <x-ui.table :caption="__('Diagnostic capture packages')">
                                <x-slot name="head">
                                    <tr>
                                        <x-ui.th>{{ __('Package') }}</x-ui.th>
                                        <x-ui.th>{{ __('Root table') }}</x-ui.th>
                                        <x-ui.th class="text-right">{{ __('Rows') }}</x-ui.th>
                                        <x-ui.th class="text-right">{{ __('Size') }}</x-ui.th>
                                        <x-ui.th>{{ __('Payload SHA-256') }}</x-ui.th>
                                        <x-ui.th>{{ __('Created') }}</x-ui.th>
                                        @if($canDelete)<x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>@endif
                                    </tr>
                                </x-slot>
                                @foreach($diagnosticPackages as $package)
                                    <tr wire:key="diagnostic-{{ $package['package_id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink" title="{{ $package['path'] }}">{{ $package['package_id'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs">
                                            <x-ui.link kind="internal" href="{{ route('admin.system.database-tables.show', $package['root_table']) }}">{{ $package['root_table'] }}</x-ui.link>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $package['rows'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-right text-sm tabular-nums text-ink">{{ $formatBytes($package['size_bytes']) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-muted">{{ $package['payload_sha256_short'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm tabular-nums text-muted"><x-ui.datetime :value="$package['created_at']" /></td>
                                        @if($canDelete)
                                            <td class="px-table-cell-x py-table-cell-y text-right">
                                                <x-ui.button variant="ghost" size="sm" wire:click="deletePackage(@js($package['path']))" wire:confirm="{{ __('Delete this diagnostic package?') }}">
                                                    <x-icon name="heroicon-o-trash" class="h-4 w-4" />
                                                    {{ __('Delete') }}
                                                </x-ui.button>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        @endif
                    </div>
                </x-ui.tab>
            </x-ui.tabs>
        </x-ui.card>
    </div>
</div>
