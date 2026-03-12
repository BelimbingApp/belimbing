<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Livewire\Settings;

use App\Modules\Core\User\Livewire\Concerns\ValidatesPasswordConfirmation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Password extends Component
{
    use ValidatesPasswordConfirmation;

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'currentPassword' => ['required', 'string', 'current_password'],
                ...$this->passwordValidationRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('currentPassword', 'password', 'passwordConfirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('currentPassword', 'password', 'passwordConfirmation');

        $this->dispatch('password-updated');
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.settings.password');
    }
}
