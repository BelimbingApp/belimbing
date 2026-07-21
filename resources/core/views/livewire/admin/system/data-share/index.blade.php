<?php

use App\Base\Database\Livewire\DataShare\Index;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;

/** @var Index $this */

$badgeVariant = static fn (string $status): string => match ($status) {
    'applied', 'ready', 'verified', 'published' => 'success',
    'conflicts', 'expired', 'revoked', 'exhausted' => 'warning',
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
$scopeOptions = collect($scopes)->map(fn (array $scope): array => [
    'value' => $scope['name'],
    'label' => "{$scope['label']} · {$scope['module_path']}",
])->values()->all();
$offerMorphType = (new \App\Base\Database\Models\DataShareTransferOffer)->getMorphClass();
$dataShareTabs = [
    ['id' => 'share', 'label' => __('Share'), 'icon' => 'heroicon-o-share'],
    ['id' => 'published', 'label' => __('Published'), 'icon' => 'heroicon-o-link'],
    ['id' => 'incoming', 'label' => __('Incoming'), 'icon' => 'heroicon-o-inbox-arrow-down'],
    ['id' => 'diagnostics', 'label' => __('Diagnostics'), 'icon' => 'heroicon-o-wrench-screwdriver'],
];

if ($instance->role->value === 'development') {
    array_splice($dataShareTabs, 1, 0, [[
        'id' => 'mirror',
        'label' => __('Mirror'),
        'icon' => 'heroicon-o-arrows-right-left',
    ]]);
}
?>

<div
    x-on:data-share-bundle-ready.window="
        (async () => {
            try {
                await navigator.clipboard.writeText($event.detail.bundle);
                await $wire.offerBundleCopied($event.detail.offerId);
            } catch (error) {
                await $wire.offerBundleCopyFailed();
            }
        })()
    "
    x-on:data-share-published.window="window.location.hash = 'published'"
>
    <x-slot name="title">{{ __('Data Share') }}</x-slot>

    <x-ui.page-header
        :title="__('Data Share')"
        :subtitle="__('Mirror exact development tables directly, or publish an immutable offer for separately reviewed promotion.')"
        :help-label="__('How Data Share works')"
    >
        <x-slot name="actions">
            <div class="flex flex-wrap items-center justify-end gap-2 text-sm text-muted">
                <x-ui.badge :variant="$instance->role->value === 'production' ? 'warning' : 'info'">
                    {{ ucfirst($instance->role->value) }}
                </x-ui.badge>
                <span class="font-mono text-xs" title="{{ $instance->id }}">{{ $instance->name }}</span>
                @if($canManageSettings)
                    <x-ui.link :href="route('admin.system.data-share.settings')">
                        {{ __('Settings') }}
                    </x-ui.link>
                @endif
            </div>
        </x-slot>
        <x-slot name="help">
            <div class="max-w-3xl text-ink">
                <p class="leading-6">
                    {{ __('Transfer offers are one-way and user-approved: the source publishes; only the target can fetch, plan, and apply. Development Mirror is a separate direct handoff for explicitly selected complete tables.') }}
                </p>
                <ol class="mt-3 list-decimal space-y-2 pl-5 leading-6 marker:font-medium marker:text-ink">
                    <li>
                        <strong class="font-medium">{{ __('Publish on the source.') }}</strong>
                        {{ __('Open Share, select the entire module or exact tables, preview the snapshot, publish it, and copy the complete offer bundle.') }}
                    </li>
                    <li>
                        <strong class="font-medium">{{ __('Review on the target.') }}</strong>
                        {{ __('Open Incoming, paste the offer, verify its source, scope, counts, size, hash, and expiry, then choose an advertised LAN or Cloudflare route.') }}
                    </li>
                    <li>
                        <strong class="font-medium">{{ __('Fetch and verify.') }}</strong>
                        {{ __('The target pulls the exact immutable stream into private staging. A partial or invalid fetch is deleted and can be retried before expiry.') }}
                    </li>
                    <li>
                        <strong class="font-medium">{{ __('Plan on the target.') }}</strong>
                        {{ __('Open Incoming, compare the source and target SHA-256, and build a plan. Any conflict blocks the entire apply.') }}
                    </li>
                    <li>
                        <strong class="font-medium">{{ __('Apply and verify.') }}</strong>
                        {{ __('Verify recovery, confirm both reviewed hashes, apply, then fetch the same immutable offer again or publish an equivalent snapshot and expect every row to be unchanged.') }}
                    </li>
                </ol>
                <p class="mt-3 leading-6 text-muted">
                    {{ __('Keep the offer bundle out of logs and committed files. Its bearer secret is never placed in the URL or package and stops working after expiry or revocation.') }}
                </p>
            </div>
        </x-slot>
    </x-ui.page-header>

    <div class="mt-4 space-y-section-gap">
        <div
            wire:loading.flex
            wire:target="previewShare,publishShare"
            class="items-center gap-3 rounded-2xl border border-border-default bg-surface-subtle px-4 py-3 text-sm text-muted"
        >
            <x-icon name="heroicon-o-arrow-path" class="h-4 w-4 shrink-0 animate-spin text-accent" />
            <span wire:loading wire:target="publishShare">{{ __('Publishing transfer offer — exporting selected tables and computing hashes…') }}</span>
            <span wire:loading wire:target="previewShare">{{ __('Reading selected tables and computing the preview hash…') }}</span>
        </div>

        @if($statusMessage)
            <x-ui.alert :variant="$statusVariant ?? 'info'">
                {{ $statusMessage }}
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-ui.tabs
                :tabs="$dataShareTabs"
                default="share"
                :wire-action="$mirrorCatalogLoaded ? null : 'dataShareTabSelected'"
            >
                <x-ui.tab id="share">
                    <div class="space-y-6">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Choose what to share') }}</h2>
                            <p class="mt-1 text-sm text-muted">
                                {{ __('Pick a module, then choose the exact tables to include. Selecting the whole module shares everything registered under it; deselect any table you want to leave out.') }}
                            </p>
                        </div>

                        @if($scopeIssues !== [])
                            <x-ui.alert variant="warning" :announce="false">
                                {{ __('Some table scopes are unavailable for sharing: :scopes. Their registered relationships do not have a safe generic insert order. Other scopes and the development mirror remain available.', [
                                    'scopes' => implode(', ', array_column($scopeIssues, 'label')),
                                ]) }}
                            </x-ui.alert>
                        @endif

                        @if($scopes === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No registered table scopes are available') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Run database registry reconciliation after migrations, then return here.') }}</p>
                            </div>
                        @else
                            <div class="max-w-5xl space-y-4">
                                <div class="space-y-4">
                                    <x-ui.combobox
                                        id="data-share-scope"
                                        :label="__('Module scope')"
                                        :placeholder="__('Select a module…')"
                                        :options="$scopeOptions"
                                        wire:model.live="scopeName"
                                    />

                                    @if($selectedScope)
                                        <div wire:key="scope-{{ $selectedScope['name'] }}" class="rounded-xl border border-border-default bg-surface-subtle">
                                            <div class="flex flex-col gap-3 border-b border-border-default p-4 sm:flex-row sm:items-center sm:justify-between">
                                                <div>
                                                    <p class="text-sm font-medium text-ink">{{ __('Registered tables') }}</p>
                                                    <p class="mt-0.5 text-xs text-muted">{{ __('Select the entire module for a complete promotion, or choose an exact table subset for this share.') }}</p>
                                                </div>
                                                <x-ui.button variant="ghost" size="sm" wire:click="selectEntireScope">
                                                    {{ __('Select entire module') }}
                                                </x-ui.button>
                                            </div>
                                            <div class="grid gap-px bg-border-default sm:grid-cols-2">
                                                @foreach($selectedScope['tables'] as $table)
                                                    <div class="bg-surface-card p-3" wire:key="scope-table-{{ $table['name'] }}">
                                                        <x-ui.checkbox
                                                            id="data-share-table-{{ $loop->index }}"
                                                            wire:model.live="selectedTables"
                                                            value="{{ $table['name'] }}"
                                                            :label="$table['name']"
                                                            :disabled="! $table['shareable']"
                                                        />
                                                        <p class="mt-1 pl-6 text-xs text-muted">
                                                            @if($table['shareable'])
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

                                @if($canPublish && $selectedScope && ! $publishedOfferBundle)
                                    <div class="flex flex-col gap-2 border-t border-border-default pt-4 sm:flex-row sm:items-center sm:justify-between">
                                        <p class="text-xs text-muted">{{ __('Preview computes a snapshot hash without publishing anything. Review it below, then publish when ready.') }}</p>
                                        <x-ui.button variant="secondary" wire:click="previewShare" wire:loading.attr="disabled" wire:target="previewShare,publishShare">
                                            <x-icon name="heroicon-o-document-magnifying-glass" class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="previewShare">{{ __('Preview') }}</span>
                                            <span wire:loading wire:target="previewShare">{{ __('Reading selected tables…') }}</span>
                                        </x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($sharePreview)
                            <div class="border-t border-border-default pt-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Reviewed share') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('Creating the package recomputes this preview and stops if any selected source row changed.') }}</p>
                                    </div>
                                    <x-ui.badge variant="info">{{ __('Conflicts rejected') }}</x-ui.badge>
                                </div>

                                <dl class="mt-4 grid gap-px overflow-hidden rounded-xl bg-border-default sm:grid-cols-3">
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tables') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ $sharePreview['counts']['tables'] }}</dd>
                                    </div>
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Records') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($sharePreview['counts']['records']) }}</dd>
                                    </div>
                                    <div class="bg-surface-subtle p-3">
                                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Estimated size') }}</dt>
                                        <dd class="mt-1 text-lg font-medium tabular-nums text-ink">{{ $formatBytes($sharePreview['estimated_bytes']) }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-4">
                                    <x-ui.disclosure :title="__('Table manifest')">
                                        <div class="divide-y divide-border-default">
                                            @foreach($sharePreview['payloads'] as $payload)
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

                                <div class="mt-4 max-w-xs">
                                    <x-ui.input
                                        id="data-share-max-downloads"
                                        type="number"
                                        min="1"
                                        max="{{ DataShareTransferOfferManager::MAX_DOWNLOADS }}"
                                        :label="__('Maximum fetches')"
                                        :help="__('How many fetches the target may start before the offer closes. A fetch claims one slot before streaming, so the limit remains reliable under concurrent requests.')"
                                        wire:model="maxDownloads"
                                    />
                                </div>

                                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="break-all font-mono text-xs text-muted" title="{{ $sharePreview['preview_sha256'] }}">
                                        {{ __('Preview SHA-256: :hash', ['hash' => $sharePreview['preview_sha256']]) }}
                                    </p>
                                    @if($canPublish)
                                        <x-ui.button wire:click="publishShare" wire:loading.attr="disabled" wire:target="publishShare">
                                            <x-icon name="heroicon-o-arrow-up-tray" class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="publishShare">{{ __('Publish') }}</span>
                                            <span wire:loading wire:target="publishShare">{{ __('Rechecking and publishing…') }}</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if($publishedOfferBundle)
                            <x-ui.alert variant="success">
                                <p class="font-medium">{{ __('Transfer offer bundle') }}</p>
                                <p class="mt-1">{{ __('Paste this on the target instance. You can copy it again from Published while the offer remains available.') }}</p>
                                <div class="mt-3">
                                    <x-ui.textarea id="data-share-published-offer" rows="7" readonly>{{ $publishedOfferBundle }}</x-ui.textarea>
                                </div>
                                <div class="mt-3" x-data="{ copyState: 'idle', copyTimer: null }">
                                    <x-ui.button
                                        variant="control"
                                        size="sm"
                                        x-on:click="
                                            clearTimeout(copyTimer);
                                            copyState = 'copying';
                                            (async () => {
                                                try {
                                                    await navigator.clipboard.writeText(@js($publishedOfferBundle));
                                                    copyState = 'copied';
                                                    copyTimer = setTimeout(() => copyState = 'idle', 1600);
                                                } catch (error) {
                                                    copyState = 'failed';
                                                }
                                            })()
                                        "
                                    >
                                        <x-icon name="heroicon-o-clipboard" class="h-4 w-4" />
                                        <span x-text="copyState === 'copying' ? @js(__('Copying…')) : (copyState === 'copied' ? @js(__('Copied')) : @js(__('Copy offer')))">{{ __('Copy offer') }}</span>
                                    </x-ui.button>
                                    <p x-cloak x-show="copyState === 'failed'" class="mt-2 text-xs text-status-danger">{{ __('Clipboard access failed. Check browser permission and try again.') }}</p>
                                </div>
                            </x-ui.alert>
                        @endif
                    </div>
                </x-ui.tab>

                @if($instance->role->value === 'development')
                    <x-ui.tab id="mirror">
                        @include('livewire.admin.system.data-share.partials.mirror')
                    </x-ui.tab>
                @endif

                <x-ui.tab id="incoming">
                    <div class="space-y-5">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Packages received by this instance') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('Paste a source-published offer. This target pulls and verifies the immutable stream; receipt alone does not plan or apply data.') }}</p>
                        </div>

                        @if($canReceive)
                            <div class="grid gap-4 rounded-xl border border-border-default bg-surface-subtle p-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.55fr)]">
                                <x-ui.textarea
                                    id="data-share-transfer-offer"
                                    :label="__('Transfer offer')"
                                    :help="__('Paste the complete transfer-offer JSON bundle from the source. The secret is held only for review and fetch.')"
                                    rows="7"
                                    wire:model.live.debounce.400ms="offerBundle"
                                    autocomplete="off"
                                />
                                <div class="space-y-3">
                                    <x-ui.button variant="control" class="w-full justify-center" wire:click="reviewOffer">
                                        <x-icon name="heroicon-o-document-magnifying-glass" class="h-4 w-4" />
                                        {{ __('Review offer') }}
                                    </x-ui.button>

                                    @if($reviewedOffer)
                                        @if(count($offerEndpoints) > 1)
                                            <x-ui.select id="data-share-offer-endpoint" :label="__('Fetch route')" :help="__('Prefer the private LAN route; use Cloudflare when private routing is unavailable.')" wire:model.live="offerEndpoint">
                                                @foreach($offerEndpoints as $endpoint)
                                                    @php
                                                        $endpointHost = (string) parse_url($endpoint, PHP_URL_HOST);
                                                        $endpointPort = parse_url($endpoint, PHP_URL_PORT);
                                                    @endphp
                                                    <option value="{{ $endpoint }}">{{ $endpointHost }}{{ $endpointPort ? ':'.$endpointPort : '' }}</option>
                                                @endforeach
                                            </x-ui.select>
                                        @endif
                                        <div class="rounded-lg bg-surface-card p-3 text-sm">
                                            <p class="font-medium text-ink">{{ $reviewedOffer['source_name'] }}</p>
                                            <p class="mt-0.5 break-all font-mono text-xs text-muted">{{ $reviewedOffer['source_id'] }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ ucfirst($reviewedOffer['source_role']) }} · {{ $reviewedOffer['scope'] }}</p>
                                            <p class="mt-2 text-xs tabular-nums text-muted">{{ __(':tables tables · :records records · :bytes', [
                                                'tables' => $reviewedOffer['counts']['tables'],
                                                'records' => app(\App\Base\Locale\Contracts\NumberDisplayService::class)->formatInteger($reviewedOffer['counts']['records']),
                                                'bytes' => $formatBytes($reviewedOffer['bytes']),
                                            ]) }}</p>
                                            <p class="mt-1 font-mono text-xs text-muted" title="{{ $reviewedOffer['sha256'] }}">{{ __('SHA-256: :hash', ['hash' => $shortHash($reviewedOffer['sha256'])]) }}</p>
                                        </div>
                                        <x-ui.button class="w-full justify-center" wire:click="fetchOffer" wire:loading.attr="disabled" wire:target="fetchOffer">
                                            <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="fetchOffer">{{ __('Fetch into Incoming') }}</span>
                                            <span wire:loading wire:target="fetchOffer">{{ __('Streaming and verifying…') }}</span>
                                        </x-ui.button>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if($incoming === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('Incoming is empty') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Paste and review a source offer above, then fetch its package when ready.') }}</p>
                            </div>
                        @else
                            <x-ui.table :caption="__('Incoming Data Share packages')">
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
                                            <p class="mt-0.5 font-mono text-xs text-muted">{{ __('Offer :offer', ['offer' => $receipt['offer_id']]) }}</p>
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
                                                        <x-ui.input id="data-share-apply-package-hash" :label="__('Package SHA-256')" wire:model="applyPackageHash" autocomplete="off" />
                                                        <x-ui.input id="data-share-apply-plan-hash" :label="__('Plan SHA-256')" wire:model="applyPlanHash" autocomplete="off" />
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

                <x-ui.tab id="published">
                    <div class="space-y-5">
                        <div class="max-w-3xl">
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Published transfer offers') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('The source owns package availability. A target may fetch the same immutable bytes until the fetch limit, expiry, or revocation; planning and apply remain target-local.') }}</p>
                        </div>

                        @if($offers === [])
                            <div class="py-8 text-center">
                                <p class="text-sm font-medium text-ink">{{ __('No transfer offers published') }}</p>
                                <p class="mt-1 text-sm text-muted">{{ __('Select and preview tables in Share, then publish the reviewed snapshot.') }}</p>
                            </div>
                        @else
                            <x-ui.table :caption="__('Source-published Data Share offers')">
                                <x-slot name="head">
                                    <tr>
                                        <x-ui.th>{{ __('Scope') }}</x-ui.th>
                                        <x-ui.th>{{ __('Size') }}</x-ui.th>
                                        <x-ui.th>{{ __('Availability') }}</x-ui.th>
                                        <x-ui.th>{{ __('Fetches') }}</x-ui.th>
                                        @if($canManageOffers)<x-ui.th><span class="sr-only">{{ __('Actions') }}</span></x-ui.th>@endif
                                    </tr>
                                </x-slot>
                                @foreach($offers as $offer)
                                    <tr wire:key="offer-{{ $offer['id'] }}">
                                        <td class="px-table-cell-x py-table-cell-y break-all font-mono text-xs text-muted">{{ $offer['scope_name'] }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $formatBytes($offer['bytes']) }}</td>
                                        <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                            <x-ui.badge :variant="$badgeVariant($offer['status'])">{{ ucfirst($offer['status']) }}</x-ui.badge>
                                            <p class="mt-1 whitespace-nowrap text-xs"><x-ui.datetime :value="$offer['expires_at']" /></p>
                                        </td>
                                        <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">
                                            @if($offer['max_downloads'] !== null)
                                                {{ __(':used of :max', ['used' => $offer['download_count'], 'max' => $offer['max_downloads']]) }}
                                            @else
                                                {{ __(':used · unlimited', ['used' => $offer['download_count']]) }}
                                            @endif
                                        </td>
                                        @if($canManageOffers)
                                            <td class="px-table-cell-x py-table-cell-y text-right">
                                                <x-ui.icon-action-group>
                                                    @if($offer['status'] === 'published')
                                                        <x-ui.icon-action
                                                            icon="heroicon-o-clipboard"
                                                            :label="__('Copy offer bundle')"
                                                            wire:click="copyOfferBundle({{ $offer['id'] }})"
                                                        />
                                                    @endif
                                                    <x-ui.record-history
                                                        :title="__('History for offer :offer', ['offer' => $offer['offer_id']])"
                                                        :auditable-type="$offerMorphType"
                                                        :auditable-id="$offer['id']"
                                                        source-capability="admin.system.data-share.view"
                                                        icon-only
                                                    />
                                                    @if($offer['status'] === 'published')
                                                        <x-ui.button variant="ghost" size="sm" wire:click="revokeOffer({{ $offer['id'] }})" wire:confirm="{{ __('Revoke this transfer offer? Future fetches will be refused immediately.') }}">
                                                            {{ __('Revoke') }}
                                                        </x-ui.button>
                                                    @endif
                                                </x-ui.icon-action-group>
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
                                <p class="mt-1 text-sm text-muted">{{ __('Open Database Tables, select a few rows, and preview their Data Share diagnostic package.') }}</p>
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
