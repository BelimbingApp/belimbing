<?php

namespace App\Base\Database\Livewire\Bridge;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\Enums\BridgeInstanceRole;
use App\Base\Database\Exceptions\BridgeTransportException;
use App\Base\Database\Models\BridgeEvent;
use App\Base\Database\Models\BridgePlan;
use App\Base\Database\Models\BridgeReceipt;
use App\Base\Database\Models\BridgeReceiveGrant;
use App\Base\Database\Services\Bridge\BridgeImportPlanner;
use App\Base\Database\Services\Bridge\BridgeInstanceIdentityResolver;
use App\Base\Database\Services\Bridge\BridgePackageApplier;
use App\Base\Database\Services\Bridge\BridgePackageExporter;
use App\Base\Database\Services\Bridge\BridgePackageSender;
use App\Base\Database\Services\Bridge\BridgeReceiveGrantManager;
use App\Base\Database\Services\Bridge\BridgeScopeCatalog;
use App\Base\Database\Services\Bridge\BridgeSettings;
use App\Base\Database\Services\Bridge\DiagnosticRowCapture;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    /** @var list<array<string, mixed>> */
    public array $scopes = [];

    /** @var list<string> */
    public array $selectedTables = [];

    /** @var list<array<string, mixed>> */
    public array $incoming = [];

    /** @var list<array<string, mixed>> */
    public array $grants = [];

    /** @var list<array<string, mixed>> */
    public array $history = [];

    /** @var list<array<string, mixed>> */
    public array $diagnosticPackages = [];

    /** @var array<string, mixed>|null */
    public ?array $exportPreview = null;

    public string $scopeName = '';

    public string $targetId = '';

    public string $targetName = '';

    public string $targetRole = 'production';

    public string $targetEndpoint = '';

    /** @var list<string> */
    public array $targetEndpoints = [];

    public string $receiveBundle = '';

    public ?string $issuedReceiveBundle = null;

    public string $grantSourceId = '';

    public string $grantSourceName = '';

    public string $grantSourceRole = 'development';

    public string $grantScope = '';

    public ?int $applyPlanId = null;

    public string $applyPackageHash = '';

    public string $applyPlanHash = '';

    public ?string $statusMessage = null;

    public ?string $statusVariant = null;

    public function mount(BridgeScopeCatalog $catalog, DiagnosticRowCapture $diagnostics): void
    {
        $this->scopes = $this->scopeRows($catalog);
        $this->scopeName = $this->scopes[0]['name'] ?? '';
        $this->grantScope = $this->scopeName;
        $this->selectEntireScope();
        $this->diagnosticPackages = $diagnostics->listPackages();
        $this->refreshOperations();
    }

    public function previewExport(BridgePackageExporter $exporter): void
    {
        $this->requireCapability('admin.system.database-bridge-export.execute');
        $this->validateExportTarget();

        try {
            $preview = $exporter->preview($this->scopeName, $this->selectedTables, $this->grantBundle());
            $this->exportPreview = [
                ...$preview->report,
                'preview_sha256' => $preview->previewHash,
                'estimated_bytes' => $preview->estimatedBytes,
            ];
            $this->setStatus(__('Export preview is ready. No package was written.'), 'success');
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function createExport(BridgePackageExporter $exporter, BridgePackageSender $sender): void
    {
        $this->requireCapability('admin.system.database-bridge-export.execute');
        $this->validateExportTarget();

        if ($this->exportPreview === null) {
            $this->setStatus(__('Preview this table selection before creating its package.'), 'warning');

            return;
        }

        try {
            $grant = $this->grantBundle();
            $result = $exporter->export(
                $this->scopeName,
                $this->selectedTables,
                $grant,
                (string) $this->exportPreview['preview_sha256'],
            );
            $sender->send($result, $grant);
            $this->setStatus(__('Package :package was streamed to :target and admitted to Incoming with SHA-256 :hash.', [
                'package' => $result->packageId,
                'target' => $grant->target->name,
                'hash' => $result->sha256,
            ]), 'success');
            $this->exportPreview = null;
            $this->receiveBundle = '';
            $this->clearExportTarget();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function planReceipt(int $receiptId, BridgeImportPlanner $planner): void
    {
        $this->requireCapability('admin.system.database-bridge-plan.review');

        try {
            $plan = $planner->plan(BridgeReceipt::query()->findOrFail($receiptId));
            $this->setStatus(
                $plan->status === 'ready'
                    ? __('Plan :hash is ready for review.', ['hash' => $plan->plan_hash])
                    : __('Plan :hash contains conflicts and cannot be applied.', ['hash' => $plan->plan_hash]),
                $plan->status === 'ready' ? 'success' : 'warning',
            );
            $this->refreshOperations();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function prepareApply(int $planId): void
    {
        $this->requireCapability('admin.system.database-bridge-apply.execute');
        $plan = BridgePlan::query()->with('receipt')->findOrFail($planId);
        $this->applyPlanId = $plan->id;
        $this->applyPackageHash = '';
        $this->applyPlanHash = '';
    }

    public function cancelApply(): void
    {
        $this->applyPlanId = null;
        $this->applyPackageHash = '';
        $this->applyPlanHash = '';
    }

    public function applySelectedPlan(BridgePackageApplier $applier, BridgeInstanceIdentityResolver $instances): void
    {
        $this->requireCapability('admin.system.database-bridge-apply.execute');

        if ($this->applyPlanId === null) {
            return;
        }

        if ($instances->current()->role === BridgeInstanceRole::Production && ! $this->recentlyAuthenticated()) {
            $this->setStatus(__('Confirm your password before applying data to production, then return to this plan.'), 'danger');

            return;
        }

        try {
            $result = $applier->apply(
                BridgePlan::query()->findOrFail($this->applyPlanId),
                trim($this->applyPackageHash),
                trim($this->applyPlanHash),
                confirmed: true,
            );
            $this->setStatus(__('Package :package was applied. The ledger records plan :plan.', [
                'package' => $result->packageId,
                'plan' => $result->planHash,
            ]), 'success');
            $this->cancelApply();
            $this->refreshOperations();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function applyReceiveBundle(): void
    {
        try {
            $grant = $this->grantBundle(requireSelectedScope: false, useSelectedEndpoint: false);
            $this->scopeName = $grant->scope;
            $this->targetId = $grant->target->id;
            $this->targetName = $grant->target->name;
            $this->targetRole = $grant->target->role->value;
            $this->targetEndpoint = $grant->endpoint;
            $this->targetEndpoints = $grant->endpoints;
            $this->selectEntireScope();
            $this->exportPreview = null;
            $this->setStatus(__('Receive key accepted for :target. Review the selected tables before previewing.', [
                'target' => $grant->target->name,
            ]), 'success');
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function issueReceiveGrant(BridgeReceiveGrantManager $manager): void
    {
        $this->requireCapability('admin.system.database-bridge-receive-grant.manage');
        $this->validate([
            'grantSourceId' => ['required', 'string', 'max:255'],
            'grantSourceName' => ['nullable', 'string', 'max:255'],
            'grantSourceRole' => ['required', 'in:development,staging'],
            'grantScope' => ['required', 'string'],
        ]);

        try {
            $bundle = $manager->issue(
                new BridgeInstanceIdentity(
                    trim($this->grantSourceId),
                    trim($this->grantSourceName) ?: trim($this->grantSourceId),
                    BridgeInstanceRole::from($this->grantSourceRole),
                ),
                $this->grantScope,
            );
            $this->issuedReceiveBundle = $bundle->toJson();
            $this->setStatus(__('One-time receive key issued. Copy it now; its plaintext secret cannot be shown again.'), 'success');
            $this->refreshOperations();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function revokeReceiveGrant(int $grantId, BridgeReceiveGrantManager $manager): void
    {
        $this->requireCapability('admin.system.database-bridge-receive-grant.manage');
        $manager->revoke(BridgeReceiveGrant::query()->findOrFail($grantId));
        $this->setStatus(__('One-time receive key revoked.'), 'success');
        $this->refreshOperations();
    }

    public function clearIssuedReceiveBundle(): void
    {
        $this->issuedReceiveBundle = null;
    }

    public function deletePackage(string $path, ?DiagnosticRowCapture $capture = null): void
    {
        $capture ??= app(DiagnosticRowCapture::class);
        $this->requireCapability('admin.system.database-bridge.delete');

        if ($capture->deletePackage($path)) {
            $this->setStatus(__('Diagnostic package deleted.'), 'success');
        } else {
            $this->setStatus(__('Package not found or outside the diagnostic storage prefix.'), 'warning');
        }

        $this->diagnosticPackages = $capture->listPackages();
    }

    public function render(BridgeInstanceIdentityResolver $instances): View
    {
        return view('livewire.admin.system.database-bridge.index', [
            'instance' => $instances->current(),
            'canExport' => $this->capabilityAllows('admin.system.database-bridge-export.execute'),
            'canPlan' => $this->capabilityAllows('admin.system.database-bridge-plan.review'),
            'canApply' => $this->capabilityAllows('admin.system.database-bridge-apply.execute'),
            'canManageReceiveGrants' => $this->capabilityAllows('admin.system.database-bridge-receive-grant.manage'),
            'canManageSettings' => $this->capabilityAllows('admin.system.database-bridge-settings.manage'),
            'canDelete' => $this->capabilityAllows('admin.system.database-bridge.delete'),
            'passwordConfirmationUrl' => Route::has('password.confirm') ? route('password.confirm') : null,
            'diskName' => app(BridgeSettings::class)->disk(),
            'pathPrefix' => app(BridgeSettings::class)->pathPrefix('bridge.path_prefix', 'bridge/diagnostics'),
        ]);
    }

    private function refreshOperations(): void
    {
        $this->incoming = BridgeReceipt::query()
            ->with(['plans', 'grant'])
            ->latest('received_at')
            ->limit(50)
            ->get()
            ->map(function (BridgeReceipt $receipt): array {
                $plan = $receipt->plans->sortByDesc('planned_at')->first();

                return [
                    'id' => $receipt->id,
                    'package_id' => $receipt->package_id,
                    'sha256' => $receipt->package_sha256,
                    'source_instance_id' => $receipt->source_instance_id,
                    'scope_name' => $receipt->scope_name,
                    'grant_id' => $receipt->grant->grant_id,
                    'status' => $receipt->status,
                    'received_at' => $receipt->received_at,
                    'expires_at' => $receipt->expires_at,
                    'metadata' => $receipt->metadata,
                    'plan' => $plan === null ? null : [
                        'id' => $plan->id,
                        'hash' => $plan->plan_hash,
                        'status' => $plan->status,
                        'summary' => $plan->summary,
                    ],
                ];
            })
            ->all();
        $this->grants = BridgeReceiveGrant::query()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (BridgeReceiveGrant $grant): array => [
                'id' => $grant->id,
                'grant_id' => $grant->grant_id,
                'source_instance_id' => $grant->expected_source_instance_id,
                'source_role' => $grant->expected_source_role,
                'target_instance_id' => $grant->target_instance_id,
                'scope_name' => $grant->scope_name,
                'max_bytes' => $grant->max_bytes,
                'status' => $grant->status === 'issued' && $grant->expires_at->isPast()
                    ? 'expired'
                    : $grant->status,
                'expires_at' => $grant->expires_at,
                'consumed_at' => $grant->consumed_at,
                'revoked_at' => $grant->revoked_at,
                'updated_at' => $grant->updated_at,
            ])
            ->all();
        $this->history = BridgeEvent::query()
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (BridgeEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'package_id' => $event->package_id,
                'plan_hash' => $event->plan_hash,
                'source_instance_id' => $event->source_instance_id,
                'scope_name' => $event->scope_name,
                'metadata' => $event->metadata,
                'error_summary' => $event->error_summary,
                'created_at' => $event->created_at,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function scopeRows(BridgeScopeCatalog $catalog): array
    {
        $rows = array_values(array_map(function ($scope): array {
            return [
                'name' => $scope->name,
                'label' => $scope->label,
                'module_path' => $scope->modulePath,
                'tables' => array_map(fn ($table): array => [
                    'name' => $table->table,
                    'primary_key' => $table->primaryKeyColumns,
                    'references' => count($table->references),
                    'bridgeable' => $table->primaryKeyColumns !== [],
                ], $scope->tables),
            ];
        }, $catalog->scopes()));

        usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $rows;
    }

    private function validateExportTarget(): void
    {
        $this->validate([
            'scopeName' => ['required', 'string'],
            'selectedTables' => ['required', 'array', 'min:1'],
            'selectedTables.*' => ['required', 'string'],
            'receiveBundle' => ['required', 'string'],
        ]);

        $this->grantBundle();
    }

    public function updatedScopeName(): void
    {
        $this->exportPreview = null;
        $this->selectEntireScope();
    }

    public function updatedSelectedTables(): void
    {
        $this->exportPreview = null;
    }

    public function updatedReceiveBundle(): void
    {
        $this->exportPreview = null;
        $this->clearExportTarget();
    }

    public function updatedTargetEndpoint(): void
    {
        $this->exportPreview = null;
    }

    public function selectEntireScope(): void
    {
        $scope = collect($this->scopes)->firstWhere('name', $this->scopeName);
        $this->selectedTables = collect($scope['tables'] ?? [])
            ->where('bridgeable', true)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function grantBundle(
        bool $requireSelectedScope = true,
        bool $useSelectedEndpoint = true,
    ): BridgeReceiveGrantBundle {
        $grant = BridgeReceiveGrantBundle::fromJson($this->receiveBundle);
        $source = app(BridgeInstanceIdentityResolver::class)->current();

        if ($grant->expectedSource->id !== $source->id
            || $grant->expectedSource->role !== $source->role
            || $grant->isExpired()
            || ($requireSelectedScope && $grant->scope !== $this->scopeName)) {
            throw BridgeTransportException::invalidGrantBundle();
        }

        return $useSelectedEndpoint && $this->targetEndpoint !== ''
            ? $grant->usingEndpoint($this->targetEndpoint)
            : $grant;
    }

    private function clearExportTarget(): void
    {
        $this->targetId = '';
        $this->targetName = '';
        $this->targetRole = 'production';
        $this->targetEndpoint = '';
        $this->targetEndpoints = [];
    }

    private function recentlyAuthenticated(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return $confirmedAt > 0 && (time() - $confirmedAt) <= (int) config('auth.password_timeout', 10800);
    }

    private function setStatus(string $message, string $variant): void
    {
        $this->statusMessage = $message;
        $this->statusVariant = $variant;
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
