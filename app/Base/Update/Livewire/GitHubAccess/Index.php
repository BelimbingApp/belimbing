<?php

namespace App\Base\Update\Livewire\GitHubAccess;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Update\Services\DeploymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Guided setup for the GitHub tokens Belimbing uses to pull updates. A deployment
 * can span several owners (public open-source modules under BelimbingApp, private
 * licensee extensions under their own accounts/orgs), and a fine-grained token is
 * scoped to one owner — so this lists each owner and stores a token per owner
 * (encrypted, `integrations.github.token.{owner}`). Public owners need none.
 */
class Index extends Component
{
    use InteractsWithNotifications;

    /** x-ui.secret-input renders this for an already-stored secret; an untouched save submits it (= keep). */
    private const SECRET_KEPT = '******';

    /** @var array<string, string> per-owner token input */
    public array $tokens = [];

    /** @var array<string, list<array{repo: string, ok: bool, status: int|null, message: string}>> per-owner test results */
    public array $testResults = [];

    public function save(string $owner, DeploymentService $deployment): void
    {
        $this->authorizeManage();

        $value = trim($this->tokens[$owner] ?? '');

        // Untouched secret field (mask sentinel, or empty while one is stored) = keep.
        if ($value === self::SECRET_KEPT || ($value === '' && $deployment->tokenFor($owner) !== null)) {
            $this->notify(__('Token for :owner is unchanged.', ['owner' => $owner]));

            return;
        }

        $this->validate(
            ["tokens.{$owner}" => ['required', 'string', 'min:20', 'max:255']],
            [],
            ["tokens.{$owner}" => __('token')],
        );

        $deployment->saveToken($owner, $value);
        unset($this->tokens[$owner], $this->testResults[$owner]);
        $this->notify(__('Token saved for :owner.', ['owner' => $owner]));
    }

    public function test(string $owner, DeploymentService $deployment): void
    {
        $this->authorizeManage();

        // Test the just-typed token if a real one was entered; otherwise the stored one.
        $typed = trim($this->tokens[$owner] ?? '');
        $token = $typed !== '' && $typed !== self::SECRET_KEPT ? $typed : null;

        $this->testResults[$owner] = $deployment->testOwner($owner, $token);
    }

    public function clearToken(string $owner, DeploymentService $deployment): void
    {
        $this->authorizeManage();

        $deployment->saveToken($owner, '');
        unset($this->tokens[$owner], $this->testResults[$owner]);
        $this->notify(__('Token cleared for :owner.', ['owner' => $owner]));
    }

    public function render(DeploymentService $deployment): View
    {
        return view('livewire.admin.system.update.github-access.index', [
            'owners' => $deployment->owners(),
        ]);
    }

    private function authorizeManage(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'admin.system.update.github-access.manage',
        );
    }
}
