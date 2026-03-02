<x-layouts.app :title="__('Add Employee')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Add Employee')" :subtitle="__('Create a new employment record')">
            <x-slot name="actions">
                <a href="{{ route('admin.employees.index') }}" class="inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-accent transition-colors hover:bg-surface-subtle">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.employees.store') }}" class="space-y-6" x-data="{ employeeType: '{{ old('employee_type', 'full_time') }}' }">
                @csrf

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.select name="company_id" label="{{ __('Company') }}" :error="$errors->first('company_id')">
                        <option value="">{{ __('Select company...') }}</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}" @selected((string) old('company_id') === (string) $company->id)>{{ $company->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="department_id" label="{{ __('Department') }}" :error="$errors->first('department_id')">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input name="employee_number" label="{{ __('Employee Number') }}" type="text" required value="{{ old('employee_number') }}" placeholder="{{ __('Employee ID or number') }}" :error="$errors->first('employee_number')" />
                    <x-ui.input name="full_name" label="{{ __('Full Name') }}" type="text" required value="{{ old('full_name') }}" placeholder="{{ __('Full legal name') }}" :error="$errors->first('full_name')" />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input name="short_name" label="{{ __('Short Name') }}" type="text" value="{{ old('short_name') }}" placeholder="{{ __('Preferred or display name') }}" :error="$errors->first('short_name')" />
                    <x-ui.input name="designation" label="{{ __('Designation') }}" type="text" value="{{ old('designation') }}" placeholder="{{ __('Job title or designation') }}" :error="$errors->first('designation')" />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.select x-model="employeeType" name="employee_type" label="{{ __('Employee Type') }}" :error="$errors->first('employee_type')">
                        <optgroup label="{{ __('Human') }}">
                            @foreach ($employeeTypes->where('code', '!=', 'digital_worker') as $type)
                                <option value="{{ $type->code }}" @selected(old('employee_type', 'full_time') === $type->code)>{{ $type->label }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="{{ __('Digital Worker') }}">
                            @foreach ($employeeTypes->where('code', 'digital_worker') as $type)
                                <option value="{{ $type->code }}" @selected(old('employee_type', 'full_time') === $type->code)>{{ $type->label }}</option>
                            @endforeach
                        </optgroup>
                    </x-ui.select>

                    <x-ui.select name="status" label="{{ __('Status') }}" :error="$errors->first('status')">
                        @foreach (['pending', 'probation', 'active', 'inactive', 'terminated'] as $status)
                            <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ __(ucfirst($status)) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input name="email" label="{{ __('Email') }}" type="email" value="{{ old('email') }}" placeholder="{{ __('Work email address') }}" :error="$errors->first('email')" />
                    <x-ui.input name="mobile_number" label="{{ __('Mobile Number') }}" type="text" value="{{ old('mobile_number') }}" placeholder="{{ __('Contact number') }}" :error="$errors->first('mobile_number')" />
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.input name="employment_start" label="{{ __('Employment Start') }}" type="date" value="{{ old('employment_start') }}" :error="$errors->first('employment_start')" />
                    <x-ui.input name="employment_end" label="{{ __('Employment End') }}" type="date" value="{{ old('employment_end') }}" :error="$errors->first('employment_end')" />
                </div>

                <div x-show="employeeType === 'digital_worker'" x-cloak>
                    <x-ui.textarea name="job_description" label="{{ __('Job Description') }}" rows="3" placeholder="{{ __('Short role label, e.g. Customer support Digital Worker') }}" :error="$errors->first('job_description')">{{ old('job_description') }}</x-ui.textarea>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.select name="supervisor_id" label="{{ __('Supervisor') }}" :error="$errors->first('supervisor_id')">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}" @selected((string) old('supervisor_id') === (string) $supervisor->id)>{{ $supervisor->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <x-ui.textarea name="metadata_json" label="{{ __('Metadata (JSON)') }}" rows="6" placeholder="{{ __('{"notes":"Additional employee information"}') }}" :error="$errors->first('metadata_json')">{{ old('metadata_json') }}</x-ui.textarea>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Add Employee') }}</x-ui.button>
                    <a href="{{ route('admin.employees.index') }}" class="inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-accent transition-colors hover:bg-surface-subtle">{{ __('Cancel') }}</a>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
