<?php

namespace App\Base\Database\Livewire\Bridge;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\Services\Bridge\DiagnosticRowCapture;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Data Bridge admin page — diagnostic capture package inventory.
 *
 * Slice 1 scope: packages created from the Database Tables row selection are
 * listed and deletable here. Development import, connected receipt, and
 * dataset-scoped exports arrive with later bridge slices.
 *
 * Read = admin.system.database-bridge.view (route middleware).
 * Delete = admin.system.database-bridge.delete (enforced inside the action).
 */
class Index extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $packages = [];

    public ?string $statusMessage = null;

    public ?string $statusVariant = null;

    public function mount(DiagnosticRowCapture $capture): void
    {
        $this->packages = $capture->listPackages();
    }

    public function deletePackage(string $path, ?DiagnosticRowCapture $capture = null): void
    {
        $capture ??= app(DiagnosticRowCapture::class);

        $this->requireCapability('admin.system.database-bridge.delete');

        if ($capture->deletePackage($path)) {
            $this->statusMessage = __('Package deleted.');
            $this->statusVariant = 'success';
        } else {
            $this->statusMessage = __('Package not found or outside the bridge storage prefix.');
            $this->statusVariant = 'warning';
        }

        $this->packages = $capture->listPackages();
    }

    public function render(): View
    {
        return view('livewire.admin.system.database-bridge.index', [
            'packages' => $this->packages,
            'canDelete' => $this->capabilityAllows('admin.system.database-bridge.delete'),
            'diskName' => (string) config('bridge.disk', 'local'),
            'pathPrefix' => (string) config('bridge.path_prefix', 'bridge/diagnostics'),
        ]);
    }

    private function requireCapability(string $capability): void
    {
        if (! $this->capabilityAllows($capability)) {
            abort(403, "Capability '{$capability}' is required.");
        }
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
