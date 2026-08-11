<?php

namespace App\Core\User\Livewire\Users;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use InteractsWithNotifications;
    use ResetsPaginationOnSearch;
    use SelectsPerPage;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    /** @var list<int|string> */
    public array $roleIds = [];

    public string $sortBy = 'name';

    public string $sortDir = 'asc';

    private const SORTABLE = [
        'name' => 'users.name',
        'email' => 'users.email',
        'company_name' => 'companies.name',
        'created_at' => 'users.created_at',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'name' => 'asc',
                'email' => 'asc',
                'company_name' => 'asc',
                'created_at' => 'desc',
            ],
        );
    }

    public function updatedRoleIds(): void
    {
        $this->resetPage();
    }

    public function delete(int $userId): void
    {
        $authUser = auth()->user();

        $actor = Actor::forUser($authUser);

        try {
            app(AuthorizationService::class)->authorize($actor, 'admin.user.delete');
        } catch (AuthorizationDeniedException) {
            $this->notifyError(__('You do not have permission to delete users.'));

            return;
        }

        $user = User::query()
            ->whereHas('company', fn (Builder $query): Builder => $query->forTenant(
                app(TenantContext::class)->requireTenantId(),
            ))
            ->findOrFail($userId);

        if ($user->id === $authUser->getAuthIdentifier()) {
            $this->notifyError(__('You cannot delete your own account.'));

            return;
        }

        $user->delete();
        $this->notify(__('User deleted successfully.'));
    }

    public function render(): View
    {
        $authUser = auth()->user();

        $actor = Actor::forUser($authUser);

        $canDelete = app(AuthorizationService::class)
            ->can($actor, 'admin.user.delete')
            ->allowed;

        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'users.name';
        $roleIds = array_values(array_unique(array_map('intval', $this->roleIds)));

        return view('livewire.admin.users.index', [
            'users' => User::query()
                ->select('users.*')
                ->with(['company', 'principalRoles.role'])
                ->leftJoin('companies', 'users.company_id', '=', 'companies.id')
                ->where('companies.tenant_id', app(TenantContext::class)->requireTenantId())
                ->when($this->search, function ($query, $search): void {
                    $query->where(function (Builder $q) use ($search): void {
                        $q->where('users.name', 'like', '%'.$search.'%')
                            ->orWhere('users.email', 'like', '%'.$search.'%');
                    });
                })
                ->when($roleIds, fn (Builder $query): Builder => $query->whereHas(
                    'principalRoles',
                    fn (Builder $roles): Builder => $roles->whereIn('role_id', $roleIds),
                ))
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('users.id')
                ->paginate($this->clampedPerPage()),
            'roleOptions' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Role $role): array => ['value' => $role->id, 'label' => $role->name])
                ->all(),
            'canDelete' => $canDelete,
        ]);
    }
}
