<?php

namespace App\Base\Foundation\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Services\DomainInstaller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Admin business-domain manager screen (admin/system/update/business-domains).
 *
 * A fresh install ships Base + Core only; this screen is where an operator
 * installs official domains (clone + migrate), disables or re-enables an
 * installed domain, and uninstalls one — GitHub-style: typing
 * "uninstall commerce" deletes the checkout but keeps every table, while
 * "uninstall commerce and drop all tables" also removes the domain's
 * tables, migration-ledger rows, and settings.
 *
 * Database state kept by an uninstall is cleaned up later on the
 * Database Residue screen (admin/system/database-residue).
 */
class DomainManager extends Component
{
    /**
     * Domain whose uninstall confirmation panel is open.
     */
    public ?string $uninstallTarget = null;

    /**
     * GitHub-style typed confirmation for uninstall.
     */
    public string $uninstallPhrase = '';

    public function install(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        // Clone + migrate outlive a default PHP execution window.
        set_time_limit(0);

        $result = $installer->install($domain);

        session()->flash($result['ok'] ? 'success' : 'error', $result['ok']
            ? __(':domain installed. Its menus and routes are live from the next page load.', ['domain' => $domain])
            : __(':domain install failed.', ['domain' => $domain]));
        session()->flash('command-log', $result['log']);

        $this->redirectRoute('admin.system.update.business-domains.index');
    }

    public function disable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->disable($domain);

        session()->flash('success', __(':domain disabled. Its code stays on disk and its data stays claimed; discovery skips it from the next page load.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.update.business-domains.index');
    }

    public function enable(string $domain, DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $reloadLog = $installer->enable($domain);

        session()->flash('success', __(':domain enabled.', ['domain' => $domain]));
        $this->flashReloadLog($reloadLog);

        $this->redirectRoute('admin.system.update.business-domains.index');
    }

    public function openUninstall(string $domain): void
    {
        $this->authorizeManage();

        $this->uninstallTarget = $domain;
        $this->uninstallPhrase = '';
        $this->resetErrorBag('uninstallPhrase');
    }

    public function cancelUninstall(): void
    {
        $this->reset('uninstallTarget', 'uninstallPhrase');
        $this->resetErrorBag('uninstallPhrase');
    }

    public function uninstall(DomainInstaller $installer): void
    {
        $this->authorizeManage();

        $domain = $this->uninstallTarget;

        if ($domain === null) {
            return;
        }

        $dropTables = $this->parseUninstallPhrase($domain);

        if ($dropTables === null) {
            $this->addError('uninstallPhrase', __('Type the exact phrase to confirm.'));

            return;
        }

        $result = $installer->uninstall($domain, $dropTables);

        session()->flash('success', $dropTables
            ? __(':domain uninstalled. :tables table(s) dropped, :ledger migration record(s) removed, :settings setting row(s) deleted.', [
                'domain' => $domain,
                'tables' => count($result['droppedTables']),
                'ledger' => $result['prunedLedger'],
                'settings' => $result['deletedSettings'],
            ])
            : __(':domain uninstalled. Its database state was kept; reinstalling adopts it again, or clean it up under Database Residue.', ['domain' => $domain]));

        $this->flashReloadLog($result['reloadLog']);

        $this->redirectRoute('admin.system.update.business-domains.index');
    }

    public function render(DomainInstaller $installer): View
    {
        return view('livewire.base.foundation.domain-manager', [
            'available' => $installer->available(),
            'installed' => $installer->installed(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Map the typed phrase to the uninstall mode; null means no match.
     */
    private function parseUninstallPhrase(string $domain): ?bool
    {
        $name = strtolower($domain);

        return match (trim($this->uninstallPhrase)) {
            "uninstall {$name}" => false,
            "uninstall {$name} and drop all tables" => true,
            default => null,
        };
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.update.business-domain.manage')
            ->allowed;
    }

    /**
     * @param  list<string>  $log
     */
    private function flashReloadLog(array $log): void
    {
        if ($log !== []) {
            session()->flash('command-log', implode("\n", $log));
        }
    }
}
