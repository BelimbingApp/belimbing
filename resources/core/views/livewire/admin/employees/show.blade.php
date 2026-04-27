<div>
    <x-slot name="title">{{ $employee->displayName() }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$employee->displayName()" :subtitle="$employee->designation ?? $employee->job_description" :pinnable="['label' => __('Administration') . '/' . __('Employees') . '/' . $employee->displayName(), 'url' => route('admin.employees.show', $employee)]">
            <x-slot name="actions">
                <a href="{{ route('admin.employees.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Employee Details') }}</h3>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.edit-in-place.text :label="__('Full Name')" :value="$employee->full_name" field="full_name" />
                    <x-ui.edit-in-place.text :label="__('Short Name')" :value="$employee->short_name" field="short_name" />
                    <x-ui.edit-in-place.text :label="__('Employee Number')" :value="$employee->employee_number" field="employee_number" monospace />
                    @if($employee->isAgent())
                        <x-ui.edit-in-place.textarea :label="__('Job Description')" :value="$employee->job_description" field="job_description" rows="2" />
                    @endif
                    <x-ui.edit-in-place.text :label="__('Designation')" :value="$employee->designation" field="designation" />
                    <x-ui.edit-in-place.text :label="__('Email')" :value="$employee->email" field="email" type="email" />
                    <x-ui.edit-in-place.text :label="__('Mobile Number')" :value="$employee->mobile_number" field="mobile_number" />
                </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Employment Information') }}</h3>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Company') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5">{{ $employee->company?->name ?? '-' }}</dd>
                    </div>
                    <x-ui.edit-in-place.select
                        :label="__('Department')"
                        :value="$employee->department_id"
                        save-method="saveDepartment"
                        save-value="val ? parseInt(val, 10) : null"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $employee->department?->type->name ?? __('None') }}</span>
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->type->name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.select
                        :label="__('Supervisor')"
                        :value="$employee->supervisor_id"
                        save-method="saveSupervisor"
                        save-value="val ? parseInt(val, 10) : null"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $employee->supervisor?->full_name ?? __('None') }}</span>
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($supervisors as $sup)
                            <option value="{{ $sup->id }}">{{ $sup->full_name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.select :label="__('Employee Type')" :value="$employee->employee_type" save-method="saveEmployeeType">
                        <x-slot name="read">
                            @if($employee->isAgent())
                                <x-ui.badge variant="info">{{ __('Agent') }}</x-ui.badge>
                            @else
                                <span class="text-sm text-ink">{{ $employee->employee_type ? ucwords(str_replace('_', ' ', $employee->employee_type)) : '-' }}</span>
                            @endif
                        </x-slot>

                        <optgroup label="{{ __('Human') }}">
                            @foreach($employeeTypes->where('code', '!=', 'agent') as $type)
                                <option value="{{ $type->code }}">{{ $type->label }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="{{ __('Agent') }}">
                            @foreach($employeeTypes->where('code', 'agent') as $type)
                                <option value="{{ $type->code }}">{{ $type->label }}</option>
                            @endforeach
                        </optgroup>
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.select :label="__('Status')" :value="$employee->status" save-method="saveStatus">
                        <x-slot name="read">
                            <x-ui.badge :variant="match($employee->status) {
                                'active' => 'success',
                                'terminated' => 'danger',
                                'probation' => 'warning',
                                default => 'default',
                            }">{{ ucfirst($employee->status) }}</x-ui.badge>
                        </x-slot>

                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="probation">{{ __('Probation') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="terminated">{{ __('Terminated') }}</option>
                    </x-ui.edit-in-place.select>
                    @if(!$employee->isAgent())
                    <x-ui.edit-in-place.select
                        :label="__('User')"
                        :value="$employee->user_id"
                        save-method="saveUser"
                        save-value="val ? parseInt(val, 10) : null"
                    >
                        <x-slot name="read">
                            @if($employee->user)
                                <a href="{{ route('admin.users.show', $employee->user) }}" wire:navigate class="text-sm text-accent hover:underline" @click.stop>{{ $employee->user->name }}</a>
                            @else
                                <span class="text-sm text-muted">{{ __('None') }}</span>
                            @endif
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    @endif
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employment Start') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5 tabular-nums"><x-ui.datetime :value="$employee->employment_start" format="date" /></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Employment End') }}</dt>
                        <dd class="text-sm text-ink px-1 -mx-1 py-0.5 tabular-nums"><x-ui.datetime :value="$employee->employment_end" format="date" /></dd>
                    </div>
                </dl>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Subordinates') }}
                    <x-ui.badge>{{ $employee->subordinates->count() }}</x-ui.badge>
                </h3>
                <div x-data="{ adding: false, selected: '' }">
                    <x-ui.button x-show="!adding" variant="primary" size="sm" @click="adding = true">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Add') }}
                    </x-ui.button>
                    <div x-show="adding" class="flex items-center gap-2">
                        <select
                            x-model="selected"
                            class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                        >
                            <option value="">{{ __('Select employee...') }}</option>
                            @foreach($availableSubordinates as $avail)
                                <option value="{{ $avail->id }}">{{ $avail->full_name }}</option>
                            @endforeach
                        </select>
                        <x-ui.button variant="primary" size="sm" @click="if (selected) { $wire.addSubordinate(parseInt(selected, 10)); selected = ''; adding = false; }">
                            {{ __('Assign') }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="sm" @click="adding = false; selected = ''">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Designation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Department') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employee->subordinates as $sub)
                            <tr wire:key="sub-{{ $sub->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <a href="{{ route('admin.employees.show', $sub) }}" wire:navigate class="text-accent hover:underline">{{ $sub->displayName() }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $sub->designation ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="match($sub->status) {
                                        'active' => 'success',
                                        'terminated' => 'danger',
                                        'probation' => 'warning',
                                        default => 'default',
                                    }">{{ ucfirst($sub->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $sub->department?->type?->name ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="removeSubordinate({{ $sub->id }})"
                                        wire:confirm="{{ __('Remove this employee as subordinate?') }}"
                                    >
                                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                                        {{ __('Remove') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No subordinates.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Addresses') }}
                    <x-ui.badge>{{ $employee->addresses->count() }}</x-ui.badge>
                </h3>
                <x-ui.button variant="primary" size="sm" wire:click="$set('showAttachModal', true)">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Attach Address') }}
                </x-ui.button>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Primary') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Priority') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid From') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Valid To') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($employee->addresses as $address)
                            <tr wire:key="address-{{ $address->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink font-medium">
                                    <a href="{{ route('admin.addresses.show', $address) }}" wire:navigate class="text-accent hover:underline">{{ $address->label ?? '-' }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted"
                                    x-data="{ editing: false, selected: @js($address->pivot->kind ?? []) }"
                                >
                                    <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <div class="flex flex-wrap gap-1">
                                            <template x-for="k in selected" :key="k">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-ink border border-border-default" x-text="k.charAt(0).toUpperCase() + k.slice(1)"></span>
                                            </template>
                                            <span x-show="selected.length === 0" class="text-muted">-</span>
                                        </div>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                    </div>
                                    <div x-show="editing" class="space-y-1">
                                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                                <input type="checkbox" value="{{ $kindOption }}" x-model="selected" class="rounded border-border-input accent-accent focus:ring-accent" />
                                                {{ __(ucfirst($kindOption)) }}
                                            </label>
                                        @endforeach
                                        <div class="flex items-center gap-2 mt-1">
                                            <button @click="$wire.saveAddressKinds({{ $address->id }}, selected); editing = false" class="px-2 py-0.5 text-xs font-medium rounded bg-accent text-accent-on hover:bg-accent-hover transition-colors">{{ __('Save') }}</button>
                                            <button @click="editing = false; selected = @js($address->pivot->kind ?? [])" class="px-2 py-0.5 text-xs font-medium rounded hover:bg-surface-subtle text-muted transition-colors">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    <button
                                        wire:click="updateAddressPivot({{ $address->id }}, 'is_primary', {{ $address->pivot->is_primary ? '0' : '1' }})"
                                        class="cursor-pointer"
                                        title="{{ __('Toggle primary') }}"
                                        aria-label="{{ __('Toggle primary') }}"
                                    >
                                        @if($address->pivot->is_primary)
                                            <x-ui.badge variant="success">{{ __('Yes') }}</x-ui.badge>
                                        @else
                                            <span class="text-muted hover:text-ink transition-colors">{{ __('No') }}</span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"
                                    x-data="{ editing: false, val: '{{ $address->pivot->priority }}' }"
                                >
                                    <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                        <span x-text="val"></span>
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                                    </div>
                                    <input
                                        x-show="editing"
                                        x-ref="input"
                                        x-model="val"
                                        @keydown.enter="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        @keydown.escape="editing = false; val = '{{ $address->pivot->priority }}'"
                                        @blur="editing = false; $wire.updateAddressPivot({{ $address->id }}, 'priority', val)"
                                        type="number"
                                        min="0"
                                        class="w-16 px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                    />
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_from ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $address->pivot->valid_to ?? '-' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="unlinkAddress({{ $address->id }})"
                                        wire:confirm="{{ __('Are you sure you want to unlink this address?') }}"
                                    >
                                        <x-icon name="heroicon-o-link-slash" class="w-4 h-4" />
                                        {{ __('Unlink') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses linked.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.modal wire:model="showAttachModal" class="max-w-lg">
            <div class="p-6 space-y-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Attach Address') }}</h3>

                <x-ui.select id="employee-attach-address" wire:model="attachAddressId" :label="__('Address')">
                        <option value="0">{{ __('Select an address...') }}</option>
                        @foreach($availableAddresses as $addr)
                            <option value="{{ $addr->id }}">{{ $addr->label }} — {{ collect([$addr->line1, $addr->locality, $addr->country_iso])->filter()->implode(', ') }}</option>
                        @endforeach
                </x-ui.select>

                <div class="space-y-1">
                    <span class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Kind') }}</span>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach(['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kindOption)
                            <label class="flex items-center gap-2 text-sm cursor-pointer">
                                <input type="checkbox" value="{{ $kindOption }}" wire:model="attachKind" class="rounded border-border-input accent-accent focus:ring-accent" />
                                {{ __(ucfirst($kindOption)) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <x-ui.checkbox id="employee-attach-is-primary" wire:model="attachIsPrimary" label="{{ __('Primary Address') }}" />

                <div>
                    <x-ui.input id="employee-attach-priority" wire:model="attachPriority" label="{{ __('Priority') }}" type="number" />
                    <p class="text-xs text-muted mt-1">{{ __('Lower number = higher priority. Used to order addresses of the same kind (0 = top).') }}</p>
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-ui.button variant="primary" wire:click="attachAddress">{{ __('Attach') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showAttachModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    </div>
</div>
