<div>
    <x-slot name="title">{{ __('Add Employee') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Add Employee')" :subtitle="__('Create a new employment record')">
            <x-slot name="actions">
                <a href="{{ route('admin.employees.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.select id="employee-company" wire:model="companyId" label="{{ __('Company') }}" :error="$errors->first('companyId')">
                        <option value="">{{ __('Select company...') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select id="employee-department" wire:model="departmentId" label="{{ __('Department') }}" :error="$errors->first('departmentId')">
                        <option value="">{{ __('None') }}</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->type->name }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="employee-number"
                        wire:model="employeeNumber"
                        label="{{ __('Employee Number') }}"
                        type="text"
                        required
                        placeholder="{{ __('Employee ID or number') }}"
                        :error="$errors->first('employeeNumber')"
                    />

                    <x-ui.input
                        id="employee-full-name"
                        wire:model="fullName"
                        label="{{ __('Full Name') }}"
                        type="text"
                        required
                        placeholder="{{ __('Full legal name') }}"
                        :error="$errors->first('fullName')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="employee-short-name"
                        wire:model="shortName"
                        label="{{ __('Short Name') }}"
                        type="text"
                        placeholder="{{ __('Preferred or display name') }}"
                        :error="$errors->first('shortName')"
                    />

                    <x-ui.input
                        id="employee-designation"
                        wire:model="designation"
                        label="{{ __('Designation') }}"
                        type="text"
                        placeholder="{{ __('Job title or designation') }}"
                        :error="$errors->first('designation')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.select id="employee-type" wire:model.live="employeeType" label="{{ __('Employee Type') }}" :error="$errors->first('employeeType')">
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
                    </x-ui.select>

                    <x-ui.select id="employee-status" wire:model="status" label="{{ __('Status') }}" :error="$errors->first('status')">
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="probation">{{ __('Probation') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                        <option value="terminated">{{ __('Terminated') }}</option>
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="employee-email"
                        wire:model="email"
                        label="{{ __('Email') }}"
                        type="email"
                        placeholder="{{ __('Work email address') }}"
                        :error="$errors->first('email')"
                    />

                    <x-ui.input
                        id="employee-mobile-number"
                        wire:model="mobileNumber"
                        label="{{ __('Mobile Number') }}"
                        type="text"
                        placeholder="{{ __('Contact number') }}"
                        :error="$errors->first('mobileNumber')"
                    />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input
                        id="employee-employment-start"
                        wire:model="employmentStart"
                        label="{{ __('Employment Start') }}"
                        type="date"
                        :error="$errors->first('employmentStart')"
                    />

                    <x-ui.input
                        id="employee-employment-end"
                        wire:model="employmentEnd"
                        label="{{ __('Employment End') }}"
                        type="date"
                        :error="$errors->first('employmentEnd')"
                    />
                </div>

                @if($employeeType === 'agent')
                <x-ui.textarea
                    id="employee-job-description"
                    wire:model="jobDescription"
                    label="{{ __('Job Description') }}"
                    rows="3"
                    placeholder="{{ __('Short role label, e.g. Customer support Agent') }}"
                    :error="$errors->first('jobDescription')"
                />
                @endif

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.select id="employee-supervisor" wire:model="supervisorId" label="{{ __('Supervisor') }}" :error="$errors->first('supervisorId')">
                        <option value="">{{ $employeeType === 'agent' ? __('Select supervisor (required)') : __('None') }}</option>
                        @foreach($supervisors as $supervisor)
                            <option value="{{ $supervisor->id }}">{{ $supervisor->full_name }}</option>
                        @endforeach
                    </x-ui.select>

                        @if($employeeType !== 'agent')
                    <x-ui.select id="employee-user-account" wire:model="userId" label="{{ __('User Account') }}" :error="$errors->first('userId')">
                        <option value="">{{ __('None') }}</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </x-ui.select>
                    @endif
                </div>

                @if(auth()->user()?->isPlatformAdmin())
                    <x-ui.textarea
                        id="employee-metadata"
                        wire:model="metadataJson"
                        label="{{ __('Metadata (JSON)') }}"
                        rows="6"
                        placeholder="{{ __('{\"notes\":\"Additional employee information\"}') }}"
                        :error="$errors->first('metadataJson')"
                    />
                @endif

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Add Employee') }}
                    </x-ui.button>
                    <a href="{{ route('admin.employees.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                        {{ __('Cancel') }}
                    </a>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
