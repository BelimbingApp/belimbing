<x-layouts.app :title="__('Set Licensee')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Set Licensee')" :subtitle="__('Designate the company operating this Belimbing instance')">
            <x-slot name="actions">
                <a href="{{ route('admin.companies.index') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl text-accent hover:bg-surface-subtle transition-colors">
                    <x-icon name="heroicon-o-arrow-left" class="w-5 h-5" />
                    {{ __('Back') }}
                </a>
            </x-slot>
        </x-ui.page-header>

        <x-ui.alert variant="info">
            {{ __('Belimbing is open-source software (AGPL-3.0). The licensee is the company operating this instance. It will be assigned id=1 and cannot be deleted.') }}
        </x-ui.alert>

        @if ($mode === 'select' && $hasCompanies)
            <x-ui.card>
                <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Select Existing Company') }}</h3>
                <form method="POST" action="{{ route('admin.setup.licensee.update') }}" class="max-w-md space-y-4">
                    @csrf
                    <input type="hidden" name="mode" value="select">
                    <x-ui.select name="selected_company_id" label="{{ __('Company') }}" :error="$errors->first('selected_company_id')">
                        <option value="">{{ __('Select a company...') }}</option>
                        @foreach($companies as $company)
                            <option value="{{ $company->id }}">{{ $company->name }}{{ $company->legal_name ? ' (' . $company->legal_name . ')' : '' }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.button type="submit" variant="primary">{{ __('Set as Licensee') }}</x-ui.button>
                    <p class="text-xs text-muted">
                        {{ __('Or') }}
                        <a href="{{ route('admin.setup.licensee', ['mode' => 'create']) }}" class="text-accent hover:underline">{{ __('create a new company') }}</a>
                    </p>
                </form>
            </x-ui.card>
        @else
            <x-ui.card>
                <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Create Licensee Company') }}</h3>
                <form method="POST" action="{{ route('admin.setup.licensee.update') }}" class="space-y-6">
                    @csrf
                    <input type="hidden" name="mode" value="create">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <x-ui.input name="name" value="{{ old('name') }}" label="{{ __('Name') }}" type="text" required :error="$errors->first('name')" />
                        <x-ui.input name="legal_name" value="{{ old('legal_name') }}" label="{{ __('Legal Name') }}" type="text" :error="$errors->first('legal_name')" />
                        <x-ui.input name="registration_number" value="{{ old('registration_number') }}" label="{{ __('Registration Number') }}" type="text" :error="$errors->first('registration_number')" />
                        <x-ui.input name="tax_id" value="{{ old('tax_id') }}" label="{{ __('Tax ID') }}" type="text" :error="$errors->first('tax_id')" />
                        <x-ui.input name="jurisdiction" value="{{ old('jurisdiction') }}" label="{{ __('Jurisdiction') }}" type="text" :error="$errors->first('jurisdiction')" />
                        <x-ui.input name="email" value="{{ old('email') }}" label="{{ __('Email') }}" type="email" :error="$errors->first('email')" />
                        <x-ui.input name="website" value="{{ old('website') }}" label="{{ __('Website') }}" type="text" :error="$errors->first('website')" />
                    </div>
                    <x-ui.button type="submit" variant="primary">{{ __('Create Licensee Company') }}</x-ui.button>
                    @if ($hasCompanies)
                        <p class="text-xs text-muted">
                            {{ __('Or') }}
                            <a href="{{ route('admin.setup.licensee', ['mode' => 'select']) }}" class="text-accent hover:underline">{{ __('select an existing company') }}</a>
                        </p>
                    @endif
                </form>
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
