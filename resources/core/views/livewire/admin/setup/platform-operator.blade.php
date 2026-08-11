<div>
    <x-slot name="title">{{ __('Set Up Platform Operator') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Set Up Platform Operator')"
            :subtitle="__('Designate the primary company of the tenant operating this Belimbing deployment')"
        >
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            {{ __('The platform operator is the party running this deployment. Its company is recorded as the operator tenant’s primary company; numeric tenant and company IDs have no special meaning.') }}
        </x-ui.alert>

        @if ($mode === 'select' && $hasCompanies)
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Select Existing Company') }}</h3>
                <p class="text-xs text-muted mb-4">{{ __('Only companies already belonging to the platform-operator tenant can be selected. Their IDs are never changed.') }}</p>

                <form wire:submit="designateExisting" class="space-y-4 max-w-md">
                    <x-ui.select id="platform-operator-company" wire:model="selectedCompanyId" label="{{ __('Company') }}" :error="$errors->first('selectedCompanyId')">
                        <option value="">{{ __('Select a company...') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}{{ $company->legal_name ? ' ('.$company->legal_name.')' : '' }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.button type="submit" variant="primary">{{ __('Set as Primary Company') }}</x-ui.button>

                    <p class="text-xs text-muted">
                        {{ __('Or') }}
                        <button type="button" wire:click="$set('mode', 'create')" class="text-accent hover:underline">{{ __('create a new company') }}</button>
                    </p>
                </form>
            </x-ui.card>
        @else
            <x-ui.card>
                <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Create Primary Company') }}</h3>

                <form wire:submit="createPrimaryCompany" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <x-ui.input id="platform-operator-name" wire:model="name" label="{{ __('Name') }}" type="text" required :error="$errors->first('name')" />
                        <x-ui.input id="platform-operator-legal-name" wire:model="legalName" label="{{ __('Legal Name') }}" type="text" :error="$errors->first('legalName')" />
                        <x-ui.input id="platform-operator-registration-number" wire:model="registrationNumber" label="{{ __('Registration Number') }}" type="text" :error="$errors->first('registrationNumber')" />
                        <x-ui.input id="platform-operator-tax-id" wire:model="taxId" label="{{ __('Tax ID') }}" type="text" :error="$errors->first('taxId')" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <x-ui.input id="platform-operator-jurisdiction" wire:model="jurisdiction" label="{{ __('Jurisdiction') }}" type="text" :error="$errors->first('jurisdiction')" />
                        <x-ui.input id="platform-operator-email" wire:model="email" label="{{ __('Email') }}" type="email" :error="$errors->first('email')" />
                        <x-ui.input id="platform-operator-website" wire:model="website" label="{{ __('Website') }}" type="text" :error="$errors->first('website')" />
                    </div>

                    <x-ui.button type="submit" variant="primary">{{ __('Create Primary Company') }}</x-ui.button>

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
