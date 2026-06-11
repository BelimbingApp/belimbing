<section class="mt-10 space-y-6">
    <div class="relative mb-5">
        <h2 class="text-2xl font-semibold">{{ __('Delete account') }}</h2>
        <p class="text-muted">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <x-ui.button wire:click="$set('showDeleteModal', true)" variant="danger" data-test="delete-user-button">
            {{ __('Delete account') }}
    </x-ui.button>

    <x-ui.modal wire:model="showDeleteModal" class="max-w-lg">
        <form method="POST" wire:submit="deleteUser" class="space-y-6">
            <div>
                <h3 class="text-xl font-semibold">{{ __('Are you sure you want to delete your account?') }}</h3>

                <p class="text-muted mt-2">
                    {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                </p>
            </div>

            <x-ui.input id="delete-user-password" wire:model="password" label="{{ __('Password') }}" type="password" />

            <x-ui.acknowledge-input
                id="delete-user-confirm"
                :phrase="\App\Modules\Core\User\Livewire\Settings\DeleteUserForm::CONFIRM_PHRASE"
                :label="__('Acknowledgment')"
                :help="__('Type :phrase to reveal the delete button.', ['phrase' => \App\Modules\Core\User\Livewire\Settings\DeleteUserForm::CONFIRM_PHRASE])"
                wire:model.live.debounce.250ms="confirmText"
            />

            <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                <x-ui.button wire:click="$set('showDeleteModal', false)" variant="ghost">
                    {{ __('Cancel') }}
                </x-ui.button>

                @if (trim($confirmText) === \App\Modules\Core\User\Livewire\Settings\DeleteUserForm::CONFIRM_PHRASE)
                    <x-ui.button type="submit" variant="danger" data-test="confirm-delete-user-button">
                        {{ __('Permanently delete account') }}
                    </x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.modal>
</section>
