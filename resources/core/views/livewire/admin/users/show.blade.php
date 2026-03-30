<div>
    <x-slot name="title">{{ $user->name }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$user->name" :subtitle="__('User details')" :pinnable="['label' => __('Administration') . '/' . __('Users') . '/' . $user->name, 'url' => route('admin.users.show', $user)]">
            <x-slot name="actions">
                <form method="POST" action="{{ route('admin.impersonate.start', $user) }}">
                    @csrf
                    <x-ui.button
                        type="submit"
                        variant="ghost"
                        :disabled="$user->id === auth()->id() || session('impersonation.original_user_id')"
                        :title="$user->id === auth()->id() ? __('You cannot impersonate yourself') : (session('impersonation.original_user_id') ? __('Cannot impersonate while impersonating') : __('Impersonate this user'))"
                    >
                        <x-icon name="heroicon-o-impersonate" class="w-4 h-4" />
                        {{ __('Impersonate') }}
                    </x-ui.button>
                </form>
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('User Details') }}</h3>

            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div x-data="{ editing: false, val: '{{ addslashes($user->name) }}' }">
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Name') }}</dt>
                    <dd class="text-sm text-ink">
                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                            <span x-text="val || '-'"></span>
                            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                        </div>
                        <input
                            x-show="editing"
                            x-ref="input"
                            x-model="val"
                            @keydown.enter="editing = false; $wire.saveField('name', val)"
                            @keydown.escape="editing = false; val = '{{ addslashes($user->name) }}'"
                            @blur="editing = false; $wire.saveField('name', val)"
                            type="text"
                            class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </dd>
                </div>
                <div x-data="{ editing: false, val: '{{ addslashes($user->email) }}' }">
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Email') }}</dt>
                    <dd class="text-sm text-ink">
                        <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                            <span x-text="val || '-'"></span>
                            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                        </div>
                        <input
                            x-show="editing"
                            x-ref="input"
                            x-model="val"
                            @keydown.enter="editing = false; $wire.saveField('email', val)"
                            @keydown.escape="editing = false; val = '{{ addslashes($user->email) }}'"
                            @blur="editing = false; $wire.saveField('email', val)"
                            type="email"
                            class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </dd>
                </div>
                <div x-data="{ editing: false, val: '{{ $user->company_id ?? '' }}' }">
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Company') }}</dt>
                    <dd class="text-sm text-ink">
                        <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                            <span>
                                @if($user->company)
                                    {{ $user->company->name }}
                                @else
                                    <span class="text-muted">{{ __('None') }}</span>
                                @endif
                            </span>
                            <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                        </div>
                        <select
                            x-show="editing"
                            x-model="val"
                            @change="editing = false; $wire.saveCompany(val ? parseInt(val, 10) : null)"
                            @keydown.escape="editing = false; val = '{{ $user->company_id ?? '' }}'"
                            @blur="editing = false"
                            class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                        >
                            <option value="">{{ __('None') }}</option>
                            @foreach($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Email Verified') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">
                        @if ($user->email_verified_at)
                            <x-ui.badge variant="success"><x-ui.datetime :value="$user->email_verified_at" /></x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">{{ __('Unverified') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Created') }}</dt>
                    <dd class="mt-0.5 text-sm text-muted tabular-nums"><x-ui.datetime :value="$user->created_at" /></dd>
                </div>
                <div>
                    <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Updated') }}</dt>
                    <dd class="mt-0.5 text-sm text-muted tabular-nums"><x-ui.datetime :value="$user->updated_at" /></dd>
                </div>
            </dl>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                {{ __('Roles & Permissions') }}
                <x-ui.badge>{{ $assignedRoles->count() }}</x-ui.badge>
            </h3>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Roles determine what this user can do. Each role grants a set of capabilities. Effective permissions show the combined result of all assigned roles.') }}</p>

            {{-- Roles --}}
            <dl class="mb-4">
                <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Roles') }}</dt>
                <dd>
                    @if($assignedRoles->isEmpty())
                        <span class="text-sm text-muted">{{ __('No roles assigned.') }}</span>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach($assignedRoles as $assignment)
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-subtle text-ink">
                                    {{ $assignment->role->name }}
                                    @if($canManageRoles)
                                        <button
                                            type="button"
                                            wire:click="removeRole({{ $assignment->id }})"
                                            class="ml-0.5 text-muted hover:text-status-danger transition-colors"
                                            title="{{ __('Remove role') }}"
                                            aria-label="{{ __('Remove role') }}"
                                        >
                                            <x-icon name="heroicon-o-x-mark" class="w-3 h-3" />
                                        </button>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif
                </dd>
            </dl>

            {{-- Assign Roles --}}
            @if($canManageRoles && $availableRoles->isNotEmpty() && !$hasGrantAll)
                <div
                    x-data="{ open: false, roleFilter: '', selected: @entangle('selectedRoleIds') }"
                    class="mb-6"
                >
                    <x-ui.button x-show="!open" variant="ghost" size="sm" @click="open = true; $nextTick(() => $refs.roleSearch?.focus())">
                        <x-icon name="heroicon-o-plus" class="w-3.5 h-3.5" />
                        {{ __('Roles') }}
                    </x-ui.button>
                    <div x-show="open" x-cloak>
                        <div>
                            <x-ui.search-input
                                x-ref="roleSearch"
                                x-model="roleFilter"
                                placeholder="{{ __('Search roles...') }}"
                            />
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-1 mt-2 max-h-48 overflow-y-auto">
                            @foreach($availableRoles as $role)
                                <label
                                    x-show="!roleFilter || @js(strtolower($role->name)).includes(roleFilter.toLowerCase()) || @js(strtolower($role->code)).includes(roleFilter.toLowerCase())"
                                    class="flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-surface-subtle cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        value="{{ $role->id }}"
                                        x-model="selected"
                                        class="rounded border-border-input accent-accent focus:ring-accent"
                                    >
                                    <span class="text-ink truncate" title="{{ $role->description ?? $role->name }}">{{ $role->name }}</span>
                                    @if ($role->company)
                                        <span class="text-muted text-xs truncate">({{ $role->company->name }})</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <x-ui.button x-show="selected.length > 0" x-cloak variant="primary" size="sm" wire:click="assignRoles">
                                {{ __('Assign') }} (<span x-text="selected.length"></span>)
                            </x-ui.button>
                            <x-ui.button variant="ghost" size="sm" @click="open = false; roleFilter = ''; selected = []">
                                {{ __('Cancel') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Effective Permissions --}}
            <div x-data="{ open: false }">
                <button
                    @click="open = !open"
                    class="flex items-center gap-2 w-full text-left"
                    type="button"
                >
                    <span class="text-[12px] shrink-0 text-accent w-3.5 text-center" aria-hidden="true">
                        <span x-show="!open">⮞</span>
                        <span x-show="open">⮟</span>
                    </span>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                        {{ __('Effective Permissions') }}
                        <x-ui.badge>{{ collect($effectivePermissions)->flatten()->count() }}</x-ui.badge>
                    </h3>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="mt-3"
                    style="display: none;"
                >
                    <p class="text-xs text-muted mb-3">{{ __('Green = from roles. Blue = direct grant. Red = denied. Click ✕ to remove or deny.') }}</p>

                    @forelse($effectivePermissions as $domain => $capabilities)
                        <dl class="mb-3">
                            <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ $domain }}</dt>
                            <dd class="flex flex-wrap gap-1">
                                @foreach($capabilities as $capability)
                                    @if (isset($directGrantIds[$capability]))
                                        <x-ui.badge variant="info">
                                            {{ $capability }}
                                            @if ($canManageRoles)
                                                <button
                                                    type="button"
                                                    wire:click="removeCapability({{ $directGrantIds[$capability] }})"
                                                    class="ml-1 text-current opacity-60 hover:opacity-100 transition-opacity"
                                                    title="{{ __('Remove direct grant') }}"
                                                    aria-label="{{ __('Remove direct grant') }}"
                                                >
                                                    <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5 stroke-[2.5]" />
                                                </button>
                                            @endif
                                        </x-ui.badge>
                                    @else
                                        <x-ui.badge variant="success">
                                            {{ $capability }}
                                            @if ($canManageRoles && $user->company_id !== null)
                                                <button
                                                    type="button"
                                                    wire:click="denyCapability('{{ $capability }}')"
                                                    class="ml-1 text-current opacity-60 hover:opacity-100 transition-opacity"
                                                    title="{{ __('Deny this capability') }}"
                                                    aria-label="{{ __('Deny this capability') }}"
                                                >
                                                    <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5 stroke-[2.5]" />
                                                </button>
                                            @endif
                                        </x-ui.badge>
                                    @endif
                                @endforeach
                            </dd>
                        </dl>
                    @empty
                        <p class="text-sm text-muted">{{ __('No permissions. Assign a role or company first.') }}</p>
                    @endforelse

                    {{-- Denied capabilities --}}
                    @if (! empty($deniedPermissions))
                        <div class="mt-4 pt-4 border-t border-border-default">
                            <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Denied') }}</div>
                            @foreach ($deniedPermissions as $domain => $capabilities)
                                <div class="mb-3">
                                    <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-1">{{ $domain }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($capabilities as $capability)
                                            <x-ui.badge variant="danger">
                                                {{ $capability }}
                                                @if ($canManageRoles)
                                                    <button
                                                        type="button"
                                                        wire:click="removeCapability({{ $directDenyIds[$capability] }})"
                                                        class="ml-1 text-current opacity-60 hover:opacity-100 transition-opacity"
                                                        title="{{ __('Remove deny') }}"
                                                        aria-label="{{ __('Remove deny') }}"
                                                    >
                                                        <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5 stroke-[2.5]" />
                                                    </button>
                                                @endif
                                            </x-ui.badge>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- Add Capabilities --}}
                    @if ($canManageRoles && $user->company_id !== null && ! empty($availableCapabilities))
                        <div
                            x-data="{ capFilter: '', selected: @entangle('selectedCapabilityKeys') }"
                            class="mt-4 pt-4 border-t border-border-default"
                        >
                            <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Add Capabilities') }}</div>
                            <x-ui.search-input
                                x-model="capFilter"
                                placeholder="{{ __('Search capabilities...') }}"
                            />
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-1 mt-2 max-h-48 overflow-y-auto">
                                @foreach ($availableCapabilities as $domain => $caps)
                                    @foreach ($caps as $cap)
                                        <label
                                            x-show="!capFilter || @js(strtolower($cap)).includes(capFilter.toLowerCase())"
                                            class="flex items-center gap-2 px-2 py-1 rounded text-sm hover:bg-surface-subtle cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                value="{{ $cap }}"
                                                x-model="selected"
                                                class="rounded border-border-input accent-accent focus:ring-accent"
                                            >
                                            <span class="text-ink truncate" title="{{ $cap }}">{{ $cap }}</span>
                                        </label>
                                    @endforeach
                                @endforeach
                            </div>
                            <div x-show="selected.length > 0" x-cloak class="mt-2">
                                <x-ui.button variant="primary" size="sm" wire:click="addCapabilities">
                                    {{ __('Add') }} (<span x-text="selected.length"></span>)
                                </x-ui.button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div x-data="{ open: false }">
                <button
                    @click="open = !open"
                    class="flex items-center gap-2 w-full text-left"
                    type="button"
                >
                    <span class="text-[12px] shrink-0 text-accent w-3.5 text-center" aria-hidden="true">
                        <span x-show="!open">⮞</span>
                        <span x-show="open">⮟</span>
                    </span>
                    <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Change Password') }}</h3>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 -translate-y-1"
                    class="mt-4"
                    style="display: none;"
                >
                    <form wire:submit="updatePassword" class="space-y-4 max-w-md">
                        <x-ui.input
                            id="user-reset-password"
                            wire:model="password"
                            label="{{ __('New Password') }}"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="{{ __('Enter new password') }}"
                            :error="$errors->first('password')"
                        />

                        <x-ui.input
                            id="user-reset-password-confirmation"
                            wire:model="passwordConfirmation"
                            label="{{ __('Confirm New Password') }}"
                            type="password"
                            required
                            autocomplete="new-password"
                            placeholder="{{ __('Confirm new password') }}"
                            :error="$errors->first('passwordConfirmation')"
                        />

                        <x-ui.button type="submit" variant="primary">
                            {{ __('Update Password') }}
                        </x-ui.button>
                    </form>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="flex items-center justify-between mb-1">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                    {{ __('Employee Records') }}
                    <x-ui.badge>{{ $user->employee ? 1 : 0 }}</x-ui.badge>
                </h3>
                @if($canManageRoles)
                    <x-ui.button variant="primary" size="sm" wire:click="$set('showAddEmployeeModal', true)">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Add Employee') }}
                    </x-ui.button>
                @endif
            </div>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Employment records linking this user to companies. A user can have multiple records across different companies (e.g. contractors). Not all employees require a user account.') }}</p>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee No.') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Department') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Designation') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employment Start') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($user->employee ? [$user->employee] : [] as $employee)
                            <tr wire:key="employee-{{ $employee->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm font-medium text-ink">
                                    <a href="{{ route('admin.employees.show', $employee) }}" wire:navigate class="text-accent hover:underline">{{ $employee->employee_number ?? '—' }}</a>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if ($employee->company)
                                        <a href="{{ route('admin.companies.show', $employee->company) }}" wire:navigate class="text-accent hover:underline">{{ $employee->company->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $employee->department?->name ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $employee->designation ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    <x-ui.badge :variant="match($employee->status) {
                                        'active' => 'success',
                                        'inactive' => 'default',
                                        'terminated' => 'danger',
                                        'pending' => 'warning',
                                        default => 'default',
                                    }">{{ ucfirst($employee->status) }}</x-ui.badge>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$employee->employment_start" format="date" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-right">
                                    <x-ui.button
                                        variant="danger-ghost"
                                        size="sm"
                                        wire:click="unlinkEmployee({{ $employee->id }})"
                                        wire:confirm="{{ __('Unlink this employee record from the user?') }}"
                                    >
                                        <x-icon name="heroicon-o-link-slash" class="w-4 h-4" />
                                        {{ __('Unlink') }}
                                    </x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No employee records.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($canManageRoles && $unlinkableEmployees->isNotEmpty())
                <div x-data="{ linking: false }" class="mt-4 pt-4 border-t border-border-default">
                    <x-ui.button x-show="!linking" variant="ghost" size="sm" @click="linking = true">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        {{ __('Link Employee') }}
                    </x-ui.button>
                    <div x-show="linking" class="flex items-end gap-2">
                        <x-ui.combobox
                            wire:model="linkEmployeeId"
                            placeholder="{{ __('Search employee...') }}"
                            :options="$unlinkableEmployees->map(fn($e) => ['value' => $e->id, 'label' => $e->full_name . ' (' . $e->employee_number . ')'])->all()"
                            class="w-64"
                        />
                        <x-ui.button variant="primary" size="sm" @click="if ($wire.linkEmployeeId) { $wire.linkEmployee($wire.linkEmployeeId); linking = false; }">
                            {{ __('Link') }}
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="sm" @click="linking = false; $wire.set('linkEmployeeId', null)">
                            {{ __('Cancel') }}
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>

        <x-ui.modal wire:model="showAddEmployeeModal" class="max-w-lg">
            <div class="p-6 space-y-4">
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Add Employee Record') }}</h3>

                <x-ui.combobox
                    wire:model="newEmpCompanyId"
                    label="{{ __('Company') }}"
                    placeholder="{{ __('Search company...') }}"
                    :options="$companies->map(fn($c) => ['value' => $c->id, 'label' => $c->name])->all()"
                    required
                    :error="$errors->first('newEmpCompanyId')"
                />

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input id="new-emp-employee-number" wire:model="newEmpEmployeeNumber" label="{{ __('Employee Number') }}" type="text" required :error="$errors->first('newEmpEmployeeNumber')" />
                    <x-ui.input id="new-emp-full-name" wire:model="newEmpFullName" label="{{ __('Full Name') }}" type="text" required :error="$errors->first('newEmpFullName')" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input id="new-emp-designation" wire:model="newEmpDesignation" label="{{ __('Designation') }}" type="text" placeholder="{{ __('Job title') }}" :error="$errors->first('newEmpDesignation')" />
                    <x-ui.input id="new-emp-employment-start" wire:model="newEmpEmploymentStart" label="{{ __('Employment Start') }}" type="date" :error="$errors->first('newEmpEmploymentStart')" />
                </div>

                <div class="flex items-center gap-4 pt-2">
                    <x-ui.button variant="primary" wire:click="addEmployee">{{ __('Create') }}</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showAddEmployeeModal', false)">{{ __('Cancel') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>

        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                {{ __('External Accesses') }}
                <x-ui.badge>{{ $user->externalAccesses->count() }}</x-ui.badge>
            </h3>
            <p class="text-xs text-muted mt-0.5 mb-4">{{ __('Portal access granted to this user by other companies. Allows customers or suppliers to view orders, invoices, and other shared data.') }}</p>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Granting Company') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Permissions') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Granted At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Expires At') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($user->externalAccesses as $access)
                            <tr wire:key="access-{{ $access->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if ($access->company)
                                        <a href="{{ route('admin.companies.show', $access->company) }}" wire:navigate class="text-accent hover:underline">{{ $access->company->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    @if ($access->permissions)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($access->permissions as $permission)
                                                <x-ui.badge variant="default">{{ $permission }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if ($access->isValid())
                                        <x-ui.badge variant="success">{{ __('Valid') }}</x-ui.badge>
                                    @elseif ($access->hasExpired())
                                        <x-ui.badge variant="danger">{{ __('Expired') }}</x-ui.badge>
                                    @elseif ($access->isPending())
                                        <x-ui.badge variant="warning">{{ __('Pending') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$access->access_granted_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$access->access_expires_at" /></td>
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
</div>
