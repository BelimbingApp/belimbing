<?php

namespace App\Modules\Core\User\Livewire\Users;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Livewire\Concerns\ValidatesPasswordConfirmation;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    use ValidatesPasswordConfirmation;

    public ?int $companyId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $companyIds = Company::query()->orderBy('name')->pluck('id');

        $this->companyId = $this->resolveDefaultCompanyId($companyIds);
    }

    /**
     * Store a newly created user.
     */
    public function store(): void
    {
        $validated = $this->validate([
            'companyId' => ['nullable', 'integer', Rule::exists(Company::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            ...$this->passwordValidationRules(),
        ]);

        $user = User::create([
            'company_id' => $validated['companyId'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Session::flash('success', __('User created successfully.'));

        $this->redirect(route('admin.users.show', $user), navigate: true);
    }

    /**
     * @param  Collection<int, int|string>  $companyIds
     */
    private function resolveDefaultCompanyId(Collection $companyIds): ?int
    {
        $authCompanyId = Auth::user()?->getCompanyId();

        if ($authCompanyId !== null && $companyIds->contains($authCompanyId)) {
            return $authCompanyId;
        }

        if ($companyIds->count() === 1) {
            return (int) $companyIds->first();
        }

        return null;
    }

    public function render(): View
    {
        return view('livewire.admin.users.create', [
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
