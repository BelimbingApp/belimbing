<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Livewire\Concerns;

use Illuminate\Validation\Rules;

trait ValidatesPasswordConfirmation
{
    /**
     * Get validation rules for a password and its confirmation field.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function passwordValidationRules(): array
    {
        return [
            'password' => ['required', 'string', Rules\Password::defaults()],
            'passwordConfirmation' => ['required', 'same:password'],
        ];
    }
}
