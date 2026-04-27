<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Livewire\Users;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Livewire\Concerns\ValidatesPasswordConfirmation;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Edit extends Component
{
    use ValidatesPasswordConfirmation;

    public User $user;

    /** @var int|string|null */
    public $companyId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->companyId = $user->company_id;
        $this->name = $user->name;
        $this->email = $user->email;
    }

    /**
     * Update the user.
     */
    public function update(): void
    {
        if ($this->companyId === '') {
            $this->companyId = null;
        }

        $rules = [
            'companyId' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user->id),
            ],
        ];

        if (! empty($this->password)) {
            $rules += $this->passwordValidationRules();
        }

        $validated = $this->validate($rules);

        $this->user->company_id = ($validated['companyId'] ?? null) ? (int) $validated['companyId'] : null;
        $this->user->name = $validated['name'];
        $this->user->email = $validated['email'];

        if (! empty($this->password)) {
            $this->user->password = Hash::make($validated['password']);
        }

        if ($this->user->isDirty('email')) {
            $this->user->email_verified_at = null;
        }

        $this->user->save();

        Session::flash('success', __('User updated successfully.'));

        $this->redirect(route('admin.users.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.admin.users.edit', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
