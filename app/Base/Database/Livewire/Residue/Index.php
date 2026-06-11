<?php

namespace App\Base\Database\Livewire\Residue;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Services\DomainResidueScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Database residue admin screen (admin/system/database-residue).
 *
 * Residue is whatever the database holds that no code on disk claims:
 * tables no discovered migration creates, rows in the migrations table
 * pointing to files that no longer exist, and settings rows no
 * Config/settings.php declares. It accumulates when migration files are
 * deleted or renamed during development and when domain uninstalls keep
 * the database.
 *
 * Residue is safe to keep — reinstalling the owning code adopts it
 * again. Cleanup actions are armed by typing DELETE and re-validate
 * against a fresh scan, so nothing claimed can ever be removed.
 */
class Index extends Component
{
    /**
     * Typed acknowledgment that arms the action buttons. The phrase states
     * what the user is accepting — permanence — rather than a magic word.
     */
    public const CONFIRM_PHRASE = 'THIS CANNOT BE UNDONE';

    /** @var list<string> */
    public array $selectedTables = [];

    /** @var list<string> */
    public array $selectedLedger = [];

    /** @var list<string> */
    public array $selectedSettings = [];

    public string $confirmText = '';

    public function dropSelectedTables(DomainResidueScanner $scanner): void
    {
        $this->authorizeManage();

        if (! $this->confirmed()) {
            return;
        }

        $result = $scanner->dropTables($this->selectedTables);

        $this->reset('selectedTables', 'confirmText');

        session()->flash('success', __(':n table(s) dropped.', ['n' => count($result['dropped'])])
            .(count($result['skipped']) > 0 ? ' '.__(':n skipped (no longer orphaned).', ['n' => count($result['skipped'])]) : ''));
    }

    public function pruneSelectedLedger(DomainResidueScanner $scanner): void
    {
        $this->authorizeManage();

        if (! $this->confirmed()) {
            return;
        }

        $deleted = $scanner->pruneLedger($this->selectedLedger);

        $this->reset('selectedLedger', 'confirmText');

        session()->flash('success', __(':n migration record(s) removed.', ['n' => $deleted]));
    }

    public function deleteSelectedSettings(DomainResidueScanner $scanner): void
    {
        $this->authorizeManage();

        if (! $this->confirmed()) {
            return;
        }

        $deleted = $scanner->deleteSettings($this->selectedSettings);

        $this->reset('selectedSettings', 'confirmText');

        session()->flash('success', __(':n setting row(s) deleted.', ['n' => $deleted]));
    }

    public function render(DomainResidueScanner $scanner): View
    {
        return view('livewire.admin.system.database-residue.index', [
            'residue' => $scanner->scan(),
            'canManage' => $this->canManage(),
            'armed' => trim($this->confirmText) === self::CONFIRM_PHRASE,
        ]);
    }

    private function authorizeManage(): void
    {
        if (! $this->canManage()) {
            abort(403);
        }
    }

    /**
     * Server-side re-check of the typed acknowledgment. The UI only shows
     * the buttons when armed, but a forged request must still be refused.
     */
    private function confirmed(): bool
    {
        if (trim($this->confirmText) === self::CONFIRM_PHRASE) {
            return true;
        }

        $this->addError('confirmText', __('Type :phrase to confirm.', ['phrase' => self::CONFIRM_PHRASE]));

        return false;
    }

    private function canManage(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.database-residue.manage')
            ->allowed;
    }
}
