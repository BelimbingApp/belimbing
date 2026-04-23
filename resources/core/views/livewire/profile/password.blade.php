<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <x-ui.input
                id="current-password"
                wire:model="currentPassword"
                label="{{ __('Current password') }}"
                type="password"
                required
                autocomplete="current-password"
            />
            <x-ui.input
                id="new-password"
                wire:model="password"
                label="{{ __('New password') }}"
                type="password"
                required
                autocomplete="new-password"
            />
            <x-ui.input
                id="password-confirmation"
                wire:model="passwordConfirmation"
                label="{{ __('Confirm Password') }}"
                type="password"
                required
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <x-ui.button type="submit" variant="primary" class="w-full" data-test="update-password-button">
                        {{ __('Save') }}
                    </x-ui.button>
                </div>

                <x-ui.action-message class="me-3" on="password-updated">
                    {{ __('Saved.') }}
                </x-ui.action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
