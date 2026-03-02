<x-layouts.app :title="$employee->displayName()">
    <div class="space-y-section-gap" x-data="{ showModal: false }">
        <x-ui.page-header :title="$employee->displayName()" :subtitle="$employee->designation ?? $employee->job_description">
            <x-slot name="actions">
                <a href="{{ route('admin.employees.index') }}" class="inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-accent transition-colors hover:bg-surface-subtle">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back to List') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="error">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee Details') }}</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ([
                    'full_name' => ['label' => __('Full Name'), 'type' => 'text'],
                    'short_name' => ['label' => __('Short Name'), 'type' => 'text'],
                    'employee_number' => ['label' => __('Employee Number'), 'type' => 'text'],
                    'designation' => ['label' => __('Designation'), 'type' => 'text'],
                    'email' => ['label' => __('Email'), 'type' => 'email'],
                    'mobile_number' => ['label' => __('Mobile Number'), 'type' => 'text'],
                ] as $field => $config)
                    <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="field" value="{{ $field }}">
                        <x-ui.input :name="'value'" :label="$config['label']" :type="$config['type']" :value="old('field') === $field ? old('value') : ($employee->{$field} ?? '')" :error="old('field') === $field ? $errors->first('value') : null" />
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                    </form>
                @endforeach

                @if ($employee->isDigitalWorker())
                    <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1 md:col-span-2">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="field" value="job_description">
                        <x-ui.textarea name="value" label="{{ __('Job Description') }}" rows="3" :error="old('field') === 'job_description' ? $errors->first('value') : null">{{ old('field') === 'job_description' ? old('value') : ($employee->job_description ?? '') }}</x-ui.textarea>
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employment Information') }}</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Company') }}</dt>
                    <dd class="px-1 py-0.5 text-sm text-ink">{{ $employee->company?->name ?? '-' }}</dd>
                </div>

                <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="department_id">
                    <x-ui.select name="value" label="{{ __('Department') }}" :error="old('field') === 'department_id' ? $errors->first('value') : null">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) (old('field') === 'department_id' ? old('value') : $employee->department_id) === (string) $department->id)>{{ $department->type->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="supervisor_id">
                    <x-ui.select name="value" label="{{ __('Supervisor') }}" :error="old('field') === 'supervisor_id' ? $errors->first('value') : null">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}" @selected((string) (old('field') === 'supervisor_id' ? old('value') : $employee->supervisor_id) === (string) $supervisor->id)>{{ $supervisor->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="employee_type">
                    <x-ui.select name="value" label="{{ __('Employee Type') }}" :error="old('field') === 'employee_type' ? $errors->first('value') : null">
                        @foreach ($employeeTypes as $type)
                            <option value="{{ $type->code }}" @selected((old('field') === 'employee_type' ? old('value') : $employee->employee_type) === $type->code)>{{ $type->label }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                </form>

                <form method="POST" action="{{ route('admin.employees.update-field', $employee) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="status">
                    <x-ui.select name="value" label="{{ __('Status') }}" :error="old('field') === 'status' ? $errors->first('value') : null">
                        @foreach (['pending', 'probation', 'active', 'inactive', 'terminated'] as $status)
                            <option value="{{ $status }}" @selected((old('field') === 'status' ? old('value') : $employee->status) === $status)>{{ __(ucfirst($status)) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                </form>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                    {{ __('Addresses') }}
                    <x-ui.badge>{{ $employee->addresses->count() }}</x-ui.badge>
                </h3>
                <x-ui.button type="button" variant="primary" size="sm" @click="showModal = true">
                    <x-icon name="heroicon-o-plus" class="h-4 w-4" />
                    {{ __('Attach Address') }}
                </x-ui.button>
            </div>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Label') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Address') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Primary') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($employee->addresses as $address)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm font-medium text-ink">
                                    <a href="{{ route('admin.addresses.show', $address) }}" class="text-accent hover:underline">{{ $address->label ?? '-' }}</a>
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ collect($address->pivot->kind ?? [])->map(fn ($item) => ucfirst($item))->implode(', ') ?: '-' }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $address->pivot->is_primary ? __('Yes') : __('No') }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-right">
                                    <form method="POST" action="{{ route('admin.employees.addresses.unlink', [$employee, $address]) }}" onsubmit="return confirm('{{ __('Are you sure you want to unlink this address?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="danger-ghost" size="sm">
                                            <x-icon name="heroicon-o-link-slash" class="h-4 w-4" />
                                            {{ __('Unlink') }}
                                        </x-ui.button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No addresses linked.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div
            x-show="showModal"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @keydown.escape.window="showModal = false"
        >
            <div class="w-full max-w-lg rounded-2xl border border-border-default bg-surface-card p-6 shadow-lg" @click.away="showModal = false">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Attach Address') }}</h3>

                <form method="POST" action="{{ route('admin.employees.addresses.attach', $employee) }}" class="mt-4 space-y-4">
                    @csrf
                    <x-ui.select name="address_id" label="{{ __('Address') }}" :error="$errors->first('address_id')">
                        <option value="">{{ __('Select an address...') }}</option>
                        @foreach ($availableAddresses as $address)
                            <option value="{{ $address->id }}">{{ $address->label }} — {{ collect([$address->line1, $address->locality, $address->country_iso])->filter()->implode(', ') }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="space-y-1">
                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Kind') }}</label>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach (['headquarters', 'billing', 'shipping', 'branch', 'other'] as $kind)
                                <label class="flex cursor-pointer items-center gap-2 text-sm">
                                    <input type="checkbox" name="kind[]" value="{{ $kind }}" class="rounded border-border-input text-accent focus:ring-accent">
                                    {{ __(ucfirst($kind)) }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <x-ui.checkbox name="is_primary" value="1" label="{{ __('Primary Address') }}" />
                    <x-ui.input name="priority" label="{{ __('Priority') }}" type="number" min="0" value="0" />

                    <div class="flex items-center gap-4 pt-2">
                        <x-ui.button type="submit" variant="primary">{{ __('Attach') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" @click="showModal = false">{{ __('Cancel') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
