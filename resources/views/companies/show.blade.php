<x-layouts.app :title="$company->name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="$company->name" :subtitle="$company->legal_name">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if ($company->isLicensee())
            <x-ui.alert variant="info">{{ __('This is the licensee company operating this Belimbing instance.') }}</x-ui.alert>
        @endif

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company Details') }}</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @php
                    $fields = [
                        ['label' => __('Name'), 'field' => 'name', 'value' => $company->name, 'type' => 'text'],
                        ['label' => __('Code'), 'field' => 'code', 'value' => $company->code, 'type' => 'text'],
                        ['label' => __('Legal Name'), 'field' => 'legal_name', 'value' => $company->legal_name, 'type' => 'text'],
                        ['label' => __('Registration Number'), 'field' => 'registration_number', 'value' => $company->registration_number, 'type' => 'text'],
                        ['label' => __('Tax ID'), 'field' => 'tax_id', 'value' => $company->tax_id, 'type' => 'text'],
                        ['label' => __('Email'), 'field' => 'email', 'value' => $company->email, 'type' => 'email'],
                        ['label' => __('Website'), 'field' => 'website', 'value' => $company->website, 'type' => 'text'],
                    ];
                @endphp

                @foreach ($fields as $item)
                    <form method="POST" action="{{ route('admin.companies.field', $company) }}" hx-patch="{{ route('admin.companies.field', $company) }}" hx-trigger="blur from:find input changed" hx-target="this" hx-swap="none">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="field" value="{{ $item['field'] }}">
                        <x-ui.input
                            name="value"
                            value="{{ old('field') === $item['field'] ? old('value') : $item['value'] }}"
                            label="{{ $item['label'] }}"
                            type="{{ $item['type'] }}"
                            onblur="this.form.requestSubmit()"
                            :error="$errors->first('value')"
                        />
                    </form>
                @endforeach

                <form method="POST" action="{{ route('admin.companies.field', $company) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="status">
                    <x-ui.select name="value" label="{{ __('Status') }}" onchange="this.form.requestSubmit()">
                        @foreach(['active', 'suspended', 'pending', 'archived'] as $status)
                            <option value="{{ $status }}" @selected($company->status === $status)>{{ __(ucfirst($status)) }}</option>
                        @endforeach
                    </x-ui.select>
                </form>

                <form method="POST" action="{{ route('admin.companies.field', $company) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="legal_entity_type_id">
                    <x-ui.select name="value" label="{{ __('Legal Entity Type') }}" onchange="this.form.requestSubmit()">
                        <option value="">{{ __('None') }}</option>
                        @foreach($legalEntityTypes as $type)
                            <option value="{{ $type->id }}" @selected((int) $company->legal_entity_type_id === $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </form>

                <form method="POST" action="{{ route('admin.companies.field', $company) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="jurisdiction">
                    <x-ui.select name="value" label="{{ __('Jurisdiction') }}" onchange="this.form.requestSubmit()">
                        <option value="">{{ __('None') }}</option>
                        @foreach($countries as $country)
                            <option value="{{ $country->iso }}" @selected($company->jurisdiction === $country->iso)>{{ $country->country }} ({{ $country->iso }})</option>
                        @endforeach
                    </x-ui.select>
                </form>

                <form method="POST" action="{{ route('admin.companies.field', $company) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="parent_id">
                    <x-ui.select name="value" label="{{ __('Parent Company') }}" onchange="this.form.requestSubmit()">
                        <option value="">{{ __('None') }}</option>
                        @foreach($parentCompanies as $parentCompany)
                            <option value="{{ $parentCompany->id }}" @selected((int) $company->parent_id === $parentCompany->id)>{{ $parentCompany->name }}</option>
                        @endforeach
                    </x-ui.select>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.companies.field', $company) }}" class="mt-4">
                @csrf
                @method('PATCH')
                <input type="hidden" name="field" value="metadata">
                <x-ui.textarea name="value" label="{{ __('Metadata (JSON)') }}" rows="6" onblur="this.form.requestSubmit()">{{ $company->metadata ? json_encode($company->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '' }}</x-ui.textarea>
            </form>

            <div class="mt-4">
                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Business Activities') }}</dt>
                <dd class="mt-1 flex flex-wrap gap-2">
                    @forelse(($company->scope_activities ?? []) as $activity)
                        @if(is_string($activity))
                            <span class="inline-flex items-center rounded-full border border-border-default bg-surface-subtle px-3 py-1 text-xs font-medium text-ink">{{ $activity }}</span>
                        @endif
                    @empty
                        <span class="text-sm text-muted">-</span>
                    @endforelse
                </dd>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                    {{ __('Addresses') }}
                    <x-ui.badge>{{ $company->addresses->count() }}</x-ui.badge>
                </h3>
                <a href="{{ route('admin.addresses.index') }}" class="inline-flex items-center gap-2 text-sm text-accent hover:underline">
                    <x-icon name="heroicon-o-map-pin" class="h-4 w-4" />
                    {{ __('Manage Addresses') }}
                </a>
            </div>
            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Primary') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($company->addresses as $address)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink">{{ $address->label ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ collect($address->pivot->kind ?? [])->implode(', ') ?: '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    <x-ui.badge :variant="$address->pivot->is_primary ? 'success' : 'default'">{{ $address->pivot->is_primary ? __('Yes') : __('No') }}</x-ui.badge>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses linked.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Departments') }} <x-ui.badge>{{ $company->departments->count() }}</x-ui.badge></h3>
                <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('admin.companies.departments', $company) }}">
                    <x-icon name="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                    {{ __('Manage') }}
                </x-ui.button>
            </div>
            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Department Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Category') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Head') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($company->departments as $department)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink">{{ $department->type?->name ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $department->type?->category ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><x-ui.badge :variant="match($department->status) { 'active' => 'success', 'suspended' => 'danger', default => 'default' }">{{ ucfirst($department->status) }}</x-ui.badge></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $department->head?->name ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No departments.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            @php
                $allRelationships = $company->relationships->map(fn ($relationship) => (object) [
                    'company' => $relationship->relatedCompany,
                    'type' => $relationship->type,
                    'direction' => __('Outgoing'),
                    'effective_from' => $relationship->effective_from,
                    'effective_to' => $relationship->effective_to,
                    'is_active' => $relationship->isActive(),
                ])->concat($company->inverseRelationships->map(fn ($relationship) => (object) [
                    'company' => $relationship->company,
                    'type' => $relationship->type,
                    'direction' => __('Incoming'),
                    'effective_from' => $relationship->effective_from,
                    'effective_to' => $relationship->effective_to,
                    'is_active' => $relationship->isActive(),
                ]));
            @endphp
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Relationships') }} <x-ui.badge>{{ $allRelationships->count() }}</x-ui.badge></h3>
                <x-ui.button variant="ghost" size="sm" as="a" href="{{ route('admin.companies.relationships', $company) }}">
                    <x-icon name="heroicon-o-cog-6-tooth" class="h-4 w-4" />
                    {{ __('Manage') }}
                </x-ui.button>
            </div>
            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Related Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Direction') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective From') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective To') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($allRelationships as $relationship)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-ink"><a href="{{ route('admin.companies.show', $relationship->company) }}" class="text-accent hover:underline">{{ $relationship->company?->name ?? '-' }}</a></td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $relationship->type?->name ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $relationship->direction }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $relationship->effective_from?->format('Y-m-d') ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $relationship->effective_to?->format('Y-m-d') ?? '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$relationship->is_active ? 'success' : 'default'">{{ $relationship->is_active ? __('Active') : __('Ended') }}</x-ui.badge></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No relationships.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('External Accesses') }} <x-ui.badge>{{ $company->externalAccesses->count() }}</x-ui.badge></h3>
            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('User') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Permissions') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Granted At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Expires At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse($company->externalAccesses as $access)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $access->user?->name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ $access->permissions ? collect($access->permissions)->implode(', ') : '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y">
                                    @if($access->isValid())
                                        <x-ui.badge variant="success">{{ __('Valid') }}</x-ui.badge>
                                    @elseif($access->hasExpired())
                                        <x-ui.badge variant="danger">{{ __('Expired') }}</x-ui.badge>
                                    @elseif($access->isPending())
                                        <x-ui.badge variant="warning">{{ __('Pending') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge>{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $access->access_granted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm tabular-nums text-muted">{{ $access->access_expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No external accesses.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
