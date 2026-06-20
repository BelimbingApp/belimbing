<?php

namespace App\Modules\Core\User\Livewire\Settings;

use App\Modules\Core\User\Livewire\Concerns\ValidatesPasswordConfirmation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
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
            Session::flash('error', __('Password was not saved. Review the highlighted fields.'));

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('currentPassword', 'password', 'passwordConfirmation');

        Session::flash('success', __('Password updated successfully.'));
    }

    public function render(): View
    {
        return view('livewire.profile.password');
    }
}
