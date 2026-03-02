<x-layouts.app :title="__('Create Company')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create Company')" :subtitle="__('Add a company record and business context')">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.companies.store') }}" class="space-y-6">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.select name="parent_id" label="{{ __('Parent Company') }}" :error="$errors->first('parent_id')">
                        <option value="">{{ __('None') }}</option>
                        @foreach($parentCompanies as $parentCompany)
                            <option value="{{ $parentCompany->id }}" @selected(old('parent_id') == $parentCompany->id)>{{ $parentCompany->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="status" label="{{ __('Status') }}" :error="$errors->first('status')">
                        @foreach(['active', 'suspended', 'pending', 'archived'] as $status)
                            <option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ __(ucfirst($status)) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.input name="name" value="{{ old('name') }}" label="{{ __('Name') }}" type="text" required placeholder="{{ __('Company display name') }}" :error="$errors->first('name')" />
                    <x-ui.input name="code" value="{{ old('code') }}" label="{{ __('Code') }}" type="text" placeholder="{{ __('Auto-generated if blank') }}" :error="$errors->first('code')" />
                    <x-ui.input name="legal_name" value="{{ old('legal_name') }}" label="{{ __('Legal Name') }}" type="text" placeholder="{{ __('Registered legal entity name') }}" :error="$errors->first('legal_name')" />
                    <x-ui.select name="legal_entity_type_id" label="{{ __('Legal Entity Type') }}" :error="$errors->first('legal_entity_type_id')">
                        <option value="">{{ __('Select type...') }}</option>
                        @foreach($legalEntityTypes as $type)
                            <option value="{{ $type->id }}" @selected((string) old('legal_entity_type_id') === (string) $type->id)>{{ $type->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="registration_number" value="{{ old('registration_number') }}" label="{{ __('Registration Number') }}" type="text" placeholder="{{ __('Business registration number') }}" :error="$errors->first('registration_number')" />
                    <x-ui.input name="tax_id" value="{{ old('tax_id') }}" label="{{ __('Tax ID') }}" type="text" placeholder="{{ __('Tax identification number') }}" :error="$errors->first('tax_id')" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <x-ui.select name="jurisdiction" label="{{ __('Jurisdiction') }}" :error="$errors->first('jurisdiction')">
                        <option value="">{{ __('Select country...') }}</option>
                        @foreach($countries as $country)
                            <option value="{{ $country->iso }}" @selected(old('jurisdiction') === $country->iso)>{{ $country->country }} ({{ $country->iso }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="email" value="{{ old('email') }}" label="{{ __('Email') }}" type="email" placeholder="{{ __('Company contact email') }}" :error="$errors->first('email')" />
                    <x-ui.input name="website" value="{{ old('website') }}" label="{{ __('Website') }}" type="text" placeholder="{{ __('example.com') }}" :error="$errors->first('website')" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.textarea name="scope_activities_json" label="{{ __('Business Activities (JSON)') }}" rows="6" :error="$errors->first('scope_activities_json')">{{ old('scope_activities_json') }}</x-ui.textarea>
                    <x-ui.textarea name="metadata_json" label="{{ __('Metadata (JSON)') }}" rows="6" :error="$errors->first('metadata_json')">{{ old('metadata_json') }}</x-ui.textarea>
                </div>

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">{{ __('Create Company') }}</x-ui.button>
                    <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">{{ __('Cancel') }}</a>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
