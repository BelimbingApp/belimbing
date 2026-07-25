<div>
    <x-slot name="title">{{ __('Set Licensee') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Set Licensee')" :subtitle="__('Designate the company operating this Belimbing instance')">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            {{ __('Belimbing is open-source software (MIT). The licensee is the company operating this instance. It will be assigned id=1 and cannot be deleted.') }}
            <br><br>
            {{ __('As the licensee, you may use, modify, distribute, and host Belimbing for any purpose, including commercially. The only condition is that copies you pass on keep the copyright and license notice. Whatever you build on the platform stays yours, and you never have to publish it.') }}
        </x-ui.alert>

        @if ($mode === 'select' && $hasCompanies)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Select Existing Company') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Choose a company to designate as the licensee. Its internal ID will be reassigned to 1.') }}</p>

                <form wire:submit="promoteExisting" class="space-y-4 max-w-md">
                    <x-ui.select id="licensee-company" wire:model="selectedCompanyId" label="{{ __('Company') }}" :error="$errors->first('selectedCompanyId')">
                        <option value="">{{ __('Select a company...') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}{{ $company->legal_name ? ' ('.$company->legal_name.')' : '' }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="flex items-center gap-4">
                        <x-ui.button type="submit" variant="primary">
                            {{ __('Set as Licensee') }}
                        </x-ui.button>
                    </div>

                    <p class="text-xs text-muted">
                        {{ __('Or') }}
                        <button type="button" wire:click="$set('mode', 'create')" class="text-accent hover:underline">{{ __('create a new company') }}</button>
                    </p>
                </form>
            </x-ui.card>
        @else
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Create Licensee Company') }}</h3>

                <form wire:submit="createLicensee" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-ui.input
                            id="licensee-name"
                            wire:model="name"
                            label="{{ __('Name') }}"
                            type="text"
                            required
                            placeholder="{{ __('Company display name') }}"
                            :error="$errors->first('name')"
                        />

                        <x-ui.input
                            id="licensee-legal-name"
                            wire:model="legalName"
                            label="{{ __('Legal Name') }}"
                            type="text"
                            placeholder="{{ __('Registered legal entity name') }}"
                            :error="$errors->first('legalName')"
                        />

                        <x-ui.input
                            id="licensee-legal-entity-type"
                            wire:model="legalEntityType"
                            label="{{ __('Legal Entity Type') }}"
                            type="text"
                            placeholder="{{ __('LLC, Corporation, Partnership, etc.') }}"
                            :error="$errors->first('legalEntityType')"
                        />

                        <x-ui.input
                            id="licensee-registration-number"
                            wire:model="registrationNumber"
                            label="{{ __('Registration Number') }}"
                            type="text"
                            placeholder="{{ __('Business registration number') }}"
                            :error="$errors->first('registrationNumber')"
                        />

                        <x-ui.input
                            id="licensee-tax-id"
                            wire:model="taxId"
                            label="{{ __('Tax ID') }}"
                            type="text"
                            placeholder="{{ __('Tax identification number') }}"
                            :error="$errors->first('taxId')"
                        />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-ui.input
                            id="licensee-jurisdiction"
                            wire:model="jurisdiction"
                            label="{{ __('Jurisdiction') }}"
                            type="text"
                            placeholder="{{ __('Country/state of registration') }}"
                            :error="$errors->first('jurisdiction')"
                        />

                        <x-ui.input
                            id="licensee-email"
                            wire:model="email"
                            label="{{ __('Email') }}"
                            type="email"
                            placeholder="{{ __('Company contact email') }}"
                            :error="$errors->first('email')"
                        />

                        <x-ui.input
                            id="licensee-website"
                            wire:model="website"
                            label="{{ __('Website') }}"
                            type="text"
                            placeholder="{{ __('example.com') }}"
                            :error="$errors->first('website')"
                        />
                    </div>

                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Licensee Company') }}
                    </x-ui.button>

                    @if ($hasCompanies)
                        <p class="text-xs text-muted">
                            {{ __('Or') }}
                            <button type="button" wire:click="$set('mode', 'select')" class="text-accent hover:underline">{{ __('select an existing company') }}</button>
                        </p>
                    @endif
                </form>
            </x-ui.card>
        @endif
    </div>
</div>
