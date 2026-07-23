<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Appearance')" :subheading="__('Choose how Belimbing looks and formats content for your account')">
        <x-ui.session-flash />

        <form wire:submit="save" class="my-6 w-full space-y-6">
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-ink">{{ __('Theme') }}</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <x-ui.radio id="theme-light" wire:model="theme" value="light" label="{{ __('Light') }}" />
                    <x-ui.radio id="theme-dark" wire:model="theme" value="dark" label="{{ __('Dark') }}" />
                    <x-ui.radio id="theme-system" wire:model="theme" value="system" label="{{ __('System') }}" />
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('System follows the light or dark preference on this device.') }}</p>
                @error('theme')
                    <p class="mt-1 text-sm text-status-danger">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-ui.select
                id="appearance-locale"
                wire:model="locale"
                :label="__('Language and region')"
                :help="__('Controls translated labels and regional number and date formatting for your account.')"
                :error="$errors->first('locale')"
            >
                <option value="">{{ __('Use installation default (:locale)', ['locale' => $installationLocale]) }}</option>
                @foreach ($localeOptions as $code => $label)
                    <option value="{{ $code }}">{{ $label }} ({{ $code }})</option>
                @endforeach
            </x-ui.select>

            <div>
                <x-ui.button type="submit" variant="primary">
                    {{ __('Save appearance') }}
                </x-ui.button>
            </div>
        </form>
    </x-settings.layout>
</section>
