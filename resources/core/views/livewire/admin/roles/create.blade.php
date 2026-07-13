<div>
    <x-slot name="title">{{ __('Create Role') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create Role')" :subtitle="__('Create a new custom role')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('admin.roles.index') }}">
                    {{ __('Back') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="createRole" class="space-y-4 max-w-lg">
                <x-ui.input
                    id="role-name"
                    wire:model="name"
                    label="{{ __('Name') }}"
                    required
                    placeholder="{{ __('e.g. Sales Manager') }}"
                    :error="$errors->first('name')"
                />

                <x-ui.input
                    id="role-code"
                    wire:model="code"
                    label="{{ __('Code') }}"
                    required
                    placeholder="{{ __('e.g. sales_manager') }}"
                    :error="$errors->first('code')"
                />
                <p class="text-xs text-muted -mt-2">{{ __('Lowercase letters, numbers, and underscores only.') }}</p>

                <x-ui.textarea
                    id="role-description"
                    wire:model="description"
                    label="{{ __('Description') }}"
                    rows="3"
                    placeholder="{{ __('What this role is for...') }}"
                    :error="$errors->first('description')"
                />

                <x-ui.select
                    id="role-company-scope"
                    wire:model="companyId"
                    label="{{ __('Company Scope') }}"
                    :error="$errors->first('companyId')"
                >
                    <option value="">{{ __('Global (all companies)') }}</option>
                    @foreach($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-ui.select>
                <p class="text-xs text-muted -mt-2">{{ __('Leave as global to make this role available to all companies, or scope it to a specific company.') }}</p>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Role') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
