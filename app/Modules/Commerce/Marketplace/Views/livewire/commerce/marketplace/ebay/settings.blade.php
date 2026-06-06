<?php

use App\Modules\Commerce\Marketplace\Livewire\Ebay\Settings;
use App\Modules\Commerce\Marketplace\Models\AccountResource;
use App\Modules\Commerce\Catalog\Models\ProductTemplate;
use Illuminate\Database\Eloquent\Collection;

/** @var Settings $this */
/** @var array<string, mixed> $group */
/** @var string $groupId */
/** @var Collection<int, AccountResource> $accountResources */
/** @var Collection<int, ProductTemplate> $productTemplates */
/** @var string|null $environment */
$paymentPolicies = $accountResources->where('kind', AccountResource::KIND_PAYMENT_POLICY);
$fulfillmentPolicies = $accountResources->where('kind', AccountResource::KIND_FULFILLMENT_POLICY);
$returnPolicies = $accountResources->where('kind', AccountResource::KIND_RETURN_POLICY);
$inventoryLocations = $accountResources->where('kind', AccountResource::KIND_INVENTORY_LOCATION);

$ebaySettingsTabs = [
    ['id' => 'connection', 'label' => __('Connection'), 'icon' => 'heroicon-o-link'],
    ['id' => 'defaults', 'label' => __('Seller defaults'), 'icon' => 'heroicon-o-adjustments-horizontal'],
    ['id' => 'categories', 'label' => __('Categories'), 'icon' => 'heroicon-o-tag'],
];
?>

<div>
    <x-slot name="title">{{ $pageTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$pageTitle" :subtitle="$pageSubtitle" />

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.tabs :tabs="$ebaySettingsTabs" default="connection">
            {{-- 1. Connection: credentials and verification, one tab, two cards --}}
            <x-ui.tab id="connection">
                <div class="space-y-section-gap">
                    {{-- Card 1 — app credentials and OAuth setup fields --}}
                    <x-ui.card>
                        <form wire:submit="save" class="space-y-6">
                            @if (($group['fields'] ?? []) === [])
                                <p class="text-sm text-muted">{{ __('No editable settings are registered for this page.') }}</p>
                            @else
                                <div wire:key="settings-group-{{ $groupId }}">
                                    @if ($group['help_title'] ?? null)
                                        <div class="mb-5" x-data="{ helpOpen: false }">
                                            <div class="flex items-center gap-2">
                                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Credentials & OAuth') }}</h2>
                                                <x-ui.help size="lg" @click="helpOpen = !helpOpen" ::aria-expanded="helpOpen" />
                                            </div>

                                            <div
                                                x-cloak
                                                x-show="helpOpen"
                                                x-transition:enter="transition-all ease-out duration-200 motion-reduce:duration-0"
                                                x-transition:enter-start="max-h-0 opacity-0"
                                                x-transition:enter-end="max-h-96 opacity-100"
                                                x-transition:leave="transition-all ease-in duration-150 motion-reduce:duration-0"
                                                x-transition:leave-start="max-h-96 opacity-100"
                                                x-transition:leave-end="max-h-0 opacity-0"
                                                class="mt-3 overflow-hidden rounded-2xl border border-border-default bg-surface-subtle text-sm text-muted"
                                                @click="helpOpen = false"
                                                role="note"
                                                aria-label="{{ __('Click to dismiss') }}"
                                            >
                                                <div class="space-y-3 p-4">
                                                    <div>
                                                        <p class="text-sm font-medium text-ink">{{ __($group['help_title']) }}</p>
                                                        @if ($group['help_intro'] ?? null)
                                                            <p class="mt-1 text-sm text-muted">{!! __($group['help_intro']) !!}</p>
                                                        @endif
                                                    </div>

                                                    @if (($group['help_steps'] ?? []) !== [])
                                                        <ol class="list-decimal space-y-1.5 pl-5 text-sm text-muted">
                                                            @foreach ($group['help_steps'] as $step)
                                                                @if (is_array($step))
                                                                    <li>
                                                                        {{ __($step['before_link'] ?? '') }}
                                                                        <a href="{{ $step['url'] }}" target="_blank" rel="noreferrer" class="text-accent hover:underline">
                                                                            {{ __($step['link_label']) }}
                                                                        </a>
                                                                        {!! __($step['after_link'] ?? '') !!}
                                                                    </li>
                                                                @else
                                                                    <li>{!! __($step) !!}</li>
                                                                @endif
                                                            @endforeach
                                                        </ol>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif

                                    @include('livewire.settings.partials.fields-grid', ['group' => $group])
                                </div>
                            @endif

                            <div class="flex items-center gap-3">
                                <x-ui.button type="submit" variant="primary">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Save Settings') }}
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>

                    {{-- Card 2 — connection & endpoint diagnostics (plan ham/06) --}}
                    @php
                        $selectedProbe = $diagnosticProbes[$this->diagnosticProbeKey] ?? null;
                        $ranProbe = isset($this->diagnostics['probe_key'])
                            ? ($diagnosticProbes[$this->diagnostics['probe_key']] ?? null)
                            : null;
                        $diagQuery = collect($this->diagnostics['query'] ?? [])
                            ->map(fn ($value, $key) => $key.'='.$value)
                            ->implode('&');
                    @endphp
                    <x-ui.card>
                        <div class="space-y-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0 space-y-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Connection & diagnostics') }}</h2>
                                        @if ($environment)
                                            <x-ui.badge :variant="$environment === 'live' ? 'success' : 'warning'">
                                                {{ $environment === 'live' ? __('Live') : __('Sandbox') }}
                                            </x-ui.badge>
                                        @endif
                                        <x-ui.badge :variant="$this->diagnosticsBadgeVariant()">
                                            {{ __(\Illuminate\Support\Str::headline($this->diagnostics['status'] ?? 'not run')) }}
                                        </x-ui.badge>
                                    </div>
                                    <p class="text-sm text-muted">
                                        {{ __('Run a read-only eBay API probe to verify the saved credentials, OAuth grant, environment, and the scope and endpoint a probe needs. Each run records an integration exchange you can open for the full payload.') }}
                                    </p>
                                </div>

                                <x-ui.button type="button" variant="outline" wire:click="connect" wire:loading.attr="disabled" wire:target="connect" class="shrink-0">
                                    <x-icon name="heroicon-o-link" class="h-4 w-4" />
                                    {{ $this->connectButtonLabel() }}
                                </x-ui.button>
                            </div>

                            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <x-ui.select
                                    id="ebay-diagnostic-probe"
                                    wire:model.live="diagnosticProbeKey"
                                    :label="__('Diagnostic probe')"
                                    :help="$selectedProbe?->intent"
                                    class="sm:max-w-sm"
                                >
                                    @foreach ($diagnosticProbes as $key => $probe)
                                        <option value="{{ $key }}">{{ $probe->label }}</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.button type="button" variant="primary" wire:click="runDiagnostics" wire:loading.attr="disabled" wire:target="runDiagnostics">
                                    <x-icon name="heroicon-o-signal" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="runDiagnostics">{{ __('Run diagnostics') }}</span>
                                    <span wire:loading wire:target="runDiagnostics">{{ __('Running...') }}</span>
                                </x-ui.button>
                            </div>

                            @if ($this->diagnostics['message'] ?? null)
                                <x-ui.alert :variant="$this->diagnosticsAlertVariant()">
                                    {{ $this->diagnostics['message'] }}
                                </x-ui.alert>
                            @else
                                <p class="text-sm text-muted">{{ __('Not run yet.') }}</p>
                            @endif

                            @if ($this->diagnostics['tested_at'] ?? null)
                                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Probe') }}</dt>
                                        <dd class="mt-1 text-ink">{{ $ranProbe?->label ?? $this->diagnostics['probe_key'] ?? '—' }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Request') }}</dt>
                                        <dd class="mt-1 break-all font-mono text-ink">{{ ($this->diagnostics['method'] ?? 'GET').' '.($this->diagnostics['endpoint'] ?? '') }}</dd>
                                    </div>
                                    @if ($diagQuery !== '')
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Query') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">{{ $diagQuery }}</dd>
                                        </div>
                                    @endif
                                    @if ($this->diagnostics['http_status'] ?? null)
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('HTTP status') }}</dt>
                                            <dd class="mt-1 font-mono text-ink">{{ $this->diagnostics['http_status'] }}</dd>
                                        </div>
                                    @endif
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Last checked') }}</dt>
                                        <dd class="mt-1 text-ink">{{ \Illuminate\Support\Carbon::parse($this->diagnostics['tested_at'])->diffForHumans() }}</dd>
                                    </div>
                                    @if ($this->diagnostics['response_excerpt'] ?? null)
                                        <div class="sm:col-span-2 lg:col-span-3">
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Response') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">{{ $this->diagnostics['response_excerpt'] }}</dd>
                                        </div>
                                    @endif
                                    @if ($this->diagnostics['exchange_id'] ?? null)
                                        <div>
                                            <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exchange') }}</dt>
                                            <dd class="mt-1 break-all font-mono text-ink">
                                                @if ($canViewExchanges)
                                                    <a href="{{ route('admin.integration.outbound-exchanges.show', $this->diagnostics['exchange_id']) }}" class="text-accent hover:underline">
                                                        {{ __('Open exchange') }}
                                                    </a>
                                                @else
                                                    {{ $this->diagnostics['exchange_id'] }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endif
                                </dl>
                            @endif
                        </div>
                    </x-ui.card>
                </div>
            </x-ui.tab>

            {{-- 2. Seller defaults: imported policies and locations --}}
            <x-ui.tab id="defaults">
                <x-ui.card>
                    <div class="space-y-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0 space-y-2">
                                <h2 class="text-base font-medium tracking-tight text-ink">{{ __('Seller setup choices') }}</h2>
                                <p class="text-sm text-muted">
                                    {{ __('Import eBay business policies and merchant locations, then choose the defaults Belimbing should use for listing drafts.') }}
                                </p>
                            </div>

                            <x-ui.button type="button" variant="outline" wire:click="importAccountSetup" wire:loading.attr="disabled" wire:target="importAccountSetup">
                                <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                                <span wire:loading.remove wire:target="importAccountSetup">{{ __('Refresh from eBay') }}</span>
                                <span wire:loading wire:target="importAccountSetup">{{ __('Refreshing...') }}</span>
                            </x-ui.button>
                        </div>

                        @if ($accountResources->isEmpty())
                            <x-ui.alert variant="info">
                                {{ __('No eBay setup choices have been imported yet. Connect eBay, grant the Sell Account and Sell Inventory scopes, then refresh from eBay.') }}
                            </x-ui.alert>
                        @else
                            <div class="grid gap-4 lg:grid-cols-2">
                                <x-ui.select
                                    id="ebay-default-payment-policy"
                                    wire:model="defaultPaymentPolicyId"
                                    :label="__('Default payment policy')"
                                    :help="__('Payment policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($paymentPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-fulfillment-policy"
                                    wire:model="defaultFulfillmentPolicyId"
                                    :label="__('Default fulfillment policy')"
                                    :help="__('Shipping/handling policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($fulfillmentPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-return-policy"
                                    wire:model="defaultReturnPolicyId"
                                    :label="__('Default return policy')"
                                    :help="__('Return policy used when a draft does not choose a different eBay policy.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($returnPolicies as $policy)
                                        <option value="{{ $policy->external_id }}">{{ $policy->name }} ({{ $policy->external_id }})</option>
                                    @endforeach
                                </x-ui.select>

                                <x-ui.select
                                    id="ebay-default-merchant-location"
                                    wire:model="defaultMerchantLocationKey"
                                    :label="__('Default merchant location')"
                                    :help="__('Inventory location key used when a draft does not choose a different eBay location.')"
                                >
                                    <option value="">{{ __('Choose when publishing') }}</option>
                                    @foreach ($inventoryLocations as $location)
                                        <option value="{{ $location->external_id }}">
                                            {{ $location->name }} ({{ $location->external_id }}){{ $location->status ? ' · '.$location->status : '' }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div class="flex items-center gap-3">
                                <x-ui.button type="button" variant="primary" wire:click="saveAccountSetupDefaults" wire:loading.attr="disabled" wire:target="saveAccountSetupDefaults">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Save setup defaults') }}
                                </x-ui.button>
                                <p class="text-xs text-muted">
                                    {{ __('Imported choices are refreshed from eBay; selected defaults are stored in Belimbing settings.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            </x-ui.tab>

            {{-- 3. Categories: template-to-eBay category mappings --}}
            <x-ui.tab id="categories">
                <x-ui.card>
                    <div class="space-y-5">
                        <div>
                            <h2 class="text-base font-medium tracking-tight text-ink">{{ __('eBay category mappings') }}</h2>
                            <p class="mt-1 text-sm text-muted">
                                {{ __('Map Belimbing templates to eBay category IDs before readiness checks or publishing. For US eBay Motors, use marketplace EBAY_MOTORS_US and category tree 100.') }}
                            </p>
                        </div>

                        @if ($productTemplates->isEmpty())
                            <x-ui.alert variant="info">{{ __('No catalog templates exist yet. Create templates in Catalog before mapping eBay categories.') }}</x-ui.alert>
                        @else
                            <x-ui.table container="flush" :caption="__('eBay category mappings')">

                                <x-slot name="head">
                                        <tr>
                                            <x-ui.th>{{ __('Template') }}</x-ui.th>
                                            <x-ui.th>{{ __('Marketplace') }}</x-ui.th>
                                            <x-ui.th>{{ __('Category tree') }}</x-ui.th>
                                            <x-ui.th>{{ __('eBay category ID') }}</x-ui.th>
                                        </tr>
                                    </x-slot>

                                        @foreach ($productTemplates as $template)
                                            <tr wire:key="ebay-template-category-{{ $template->id }}">
                                                <td class="px-table-cell-x py-table-cell-y">
                                                    <div class="text-sm font-medium text-ink">{{ $template->name }}</div>
                                                    <div class="mt-1 text-xs text-muted">{{ $template->category?->name ?? __('Any category') }}</div>
                                                </td>
                                                <td class="px-table-cell-x py-table-cell-y min-w-48">
                                                    <x-ui.input
                                                        id="ebay-template-{{ $template->id }}-marketplace"
                                                        wire:model="templateCategoryMappings.{{ $template->id }}.marketplace_id"
                                                        :label="__('Marketplace')"
                                                        :error="$errors->first('templateCategoryMappings.' . $template->id . '.marketplace_id')"
                                                    />
                                                </td>
                                                <td class="px-table-cell-x py-table-cell-y min-w-36">
                                                    <x-ui.input
                                                        id="ebay-template-{{ $template->id }}-category-tree"
                                                        wire:model="templateCategoryMappings.{{ $template->id }}.category_tree_id"
                                                        :label="__('Tree')"
                                                        :error="$errors->first('templateCategoryMappings.' . $template->id . '.category_tree_id')"
                                                    />
                                                </td>
                                                <td class="px-table-cell-x py-table-cell-y min-w-48">
                                                    <x-ui.input
                                                        id="ebay-template-{{ $template->id }}-category-id"
                                                        wire:model="templateCategoryMappings.{{ $template->id }}.category_id"
                                                        :label="__('Category ID')"
                                                        :error="$errors->first('templateCategoryMappings.' . $template->id . '.category_id')"
                                                    />
                                                </td>
                                            </tr>
                                        @endforeach

                            </x-ui.table>

                            <div class="flex flex-wrap items-center gap-3">
                                <x-ui.button type="button" variant="primary" wire:click="saveTemplateCategoryMappings" wire:loading.attr="disabled" wire:target="saveTemplateCategoryMappings">
                                    <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                    {{ __('Save category mappings') }}
                                </x-ui.button>

                                <x-ui.button type="button" variant="outline" wire:click="refreshMappedCategoryMetadata" wire:loading.attr="disabled" wire:target="refreshMappedCategoryMetadata">
                                    <x-icon name="heroicon-o-arrow-path" class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="refreshMappedCategoryMetadata">{{ __('Refresh metadata') }}</span>
                                    <span wire:loading wire:target="refreshMappedCategoryMetadata">{{ __('Refreshing...') }}</span>
                                </x-ui.button>

                                <p class="text-xs text-muted">
                                    {{ __('Refresh pulls category aspects, compatibility properties, compatibility policy, and condition policy for mapped categories.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </x-ui.card>
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
