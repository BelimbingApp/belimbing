<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Livewire\Users;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function delete(int $userId): void
    {
        $authUser = auth()->user();

        $actor = Actor::forUser($authUser);

        try {
            app(AuthorizationService::class)->authorize($actor, 'core.user.delete');
        } catch (AuthorizationDeniedException) {
            Session::flash('error', __('You do not have permission to delete users.'));

            return;
        }

        $user = User::findOrFail($userId);

        if ($user->id === $authUser->getAuthIdentifier()) {
            Session::flash('error', __('You cannot delete your own account.'));

            return;
        }

        $user->delete();
        Session::flash('success', __('User deleted successfully.'));
    }

    public function render(): View
    {
        $authUser = auth()->user();

        $actor = Actor::forUser($authUser);

        $canDelete = app(AuthorizationService::class)
            ->can($actor, 'core.user.delete')
            ->allowed;

        return view('livewire.admin.users.index', [
            'users' => User::query()
                ->with('company')
                ->when($this->search, function ($query, $search): void {
                    $query->where(function ($q) use ($search): void {
                        $q->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%');
                    });
                })
                ->latest()
                ->paginate(10),
            'canDelete' => $canDelete,
        ]);
    }
}
