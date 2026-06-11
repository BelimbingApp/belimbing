<?php

namespace App\Modules\Core\User\Livewire\Settings;

use App\Modules\Core\User\Actions\Logout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DeleteUserForm extends Component
{
    /**
     * Typed acknowledgment that reveals the delete button. The password
     * below re-authenticates; this phrase makes the permanence explicit.
     */
    public const CONFIRM_PHRASE = 'THIS CANNOT BE UNDONE';

    public string $password = '';

    public string $confirmText = '';

    public bool $showDeleteModal = false;

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        // The UI only shows the button when the phrase is typed, but a
        // forged request must still be refused.
        if (trim($this->confirmText) !== self::CONFIRM_PHRASE) {
            $this->addError('confirmText', __('Type :phrase to confirm.', ['phrase' => self::CONFIRM_PHRASE]));

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'currentPassword'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.profile.delete-user-form');
    }
}
