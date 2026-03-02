<section class="mt-10 space-y-6" x-data="{ showModal: false }">
    <div class="relative mb-5">
        <h2 class="text-2xl font-semibold">{{ __('Delete account') }}</h2>
        <p class="text-muted">{{ __('Delete your account and all of its resources') }}</p>
    </div>

    <x-ui.button type="button" @click="showModal = true" variant="danger" data-test="delete-user-button">
        {{ __('Delete account') }}
    </x-ui.button>

    <div
        x-show="showModal"
        x-cloak
        @keydown.escape.window="showModal = false"
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none;"
    >
        <div
            x-show="showModal"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="showModal = false"
            class="fixed inset-0 bg-black/50"
        ></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div
                x-show="showModal"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                @click.stop
                class="relative w-full max-w-lg rounded-2xl border border-border-default bg-surface-card p-6 shadow-xl"
            >
                <form method="POST" action="{{ route('account.destroy') }}" class="space-y-6">
                    @csrf
                    @method('DELETE')

                    <div>
                        <h3 class="text-xl font-semibold">{{ __('Are you sure you want to delete your account?') }}</h3>

                        <p class="mt-2 text-muted">
                            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
                        </p>
                    </div>

                    <x-ui.input
                        name="password"
                        label="{{ __('Password') }}"
                        type="password"
                        :error="$errors->first('password')"
                    />

                    <div class="flex justify-end space-x-2 rtl:space-x-reverse">
                        <x-ui.button type="button" @click="showModal = false" variant="ghost">
                            {{ __('Cancel') }}
                        </x-ui.button>

                        <x-ui.button type="submit" variant="danger" data-test="confirm-delete-user-button">
                            {{ __('Delete account') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
