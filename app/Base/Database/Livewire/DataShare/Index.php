<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Livewire\Concerns\AuthorizesDataShareOperations;
use App\Base\Database\Livewire\DataShare\Concerns\ManagesDataSharePageState;
use App\Base\Database\Livewire\DataShare\Concerns\ManagesDevelopmentTableMirror;
use App\Base\Database\Livewire\DataShare\Concerns\ManagesTransferOffers;
use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\DataSharePackageApplier;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareSettings;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Database\Services\DataShare\DiagnosticRowCapture;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Throwable;

class Index extends Component
{
    use AuthorizesDataShareOperations;
    use ManagesDataSharePageState;
    use ManagesDevelopmentTableMirror;
    use ManagesTransferOffers;

    /** @var list<array<string, mixed>> */
    public array $scopes = [];

    /** @var list<array{name: string, label: string, message: string}> */
    public array $scopeIssues = [];

    /** @var list<string> */
    public array $selectedTables = [];

    /** @var list<array<string, mixed>> */
    public array $incoming = [];

    /** @var list<array<string, mixed>> */
    public array $offers = [];

    /** @var list<array<string, mixed>> */
    public array $diagnosticPackages = [];

    /** @var array<string, mixed>|null */
    public ?array $sharePreview = null;

    public int $maxDownloads = 1;

    public string $scopeName = '';

    public string $offerBundle = '';

    public ?string $publishedOfferBundle = null;

    public string $offerEndpoint = '';

    /** @var list<string> */
    public array $offerEndpoints = [];

    /** @var array<string, mixed>|null */
    public ?array $reviewedOffer = null;

    public ?int $applyPlanId = null;

    public string $applyPackageHash = '';

    public string $applyPlanHash = '';

    public ?string $statusMessage = null;

    public ?string $statusVariant = null;

    public function mount(DataShareScopeCatalog $catalog, DiagnosticRowCapture $diagnostics): void
    {
        $this->scopes = $this->scopeRows($catalog);
        $this->diagnosticPackages = $diagnostics->listPackages();
        $this->refreshOperations();
    }

    public function previewShare(DataSharePackageExporter $exporter): void
    {
        $this->requireCapability('admin.system.data-share-offer.create');
        $this->validateShareSelection();

        try {
            $preview = $exporter->preview($this->scopeName, $this->selectedTables);
            $this->sharePreview = [
                ...$preview->report,
                'preview_sha256' => $preview->previewHash,
                'estimated_bytes' => $preview->estimatedBytes,
            ];
            $this->setStatus(__('Share preview is ready. No package was written.'), 'success');
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function publishShare(DataShareTransferOfferManager $offers): void
    {
        $this->requireCapability('admin.system.data-share-offer.create');
        $this->validateShareSelection();

        if ($this->sharePreview === null) {
            $this->setStatus(__('Preview this table selection before creating its package.'), 'warning');

            return;
        }

        try {
            $offer = $offers->publish(
                $this->scopeName,
                $this->selectedTables,
                (string) $this->sharePreview['preview_sha256'],
                maxDownloads: $this->maxDownloads,
            );
            $this->publishedOfferBundle = $offer->toJson();
            $this->setStatus(__('Transfer offer :offer is published. Copy its bundle from the Published tab.', [
                'offer' => $offer->offerId,
            ]), 'success');
            $this->sharePreview = null;
            $this->refreshOperations();
            $this->dispatch('data-share-published');
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function planReceipt(int $receiptId, DataShareImportPlanner $planner): void
    {
        $this->requireCapability('admin.system.data-share-plan.review');

        try {
            $plan = $planner->plan(DataShareReceipt::query()->findOrFail($receiptId));
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
        $this->requireCapability('admin.system.data-share-apply.execute');
        $plan = DataSharePlan::query()->with('receipt')->findOrFail($planId);
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

    public function applySelectedPlan(DataSharePackageApplier $applier, DataShareInstanceIdentityResolver $instances): void
    {
        $this->requireCapability('admin.system.data-share-apply.execute');

        if ($this->applyPlanId === null) {
            return;
        }

        if ($instances->current()->role === DataShareInstanceRole::Production && ! $this->recentlyAuthenticated()) {
            $this->setStatus(__('Confirm your password before applying data to production, then return to this plan.'), 'danger');

            return;
        }

        try {
            $result = $applier->apply(
                DataSharePlan::query()->findOrFail($this->applyPlanId),
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

    public function deletePackage(string $path, ?DiagnosticRowCapture $capture = null): void
    {
        $capture ??= app(DiagnosticRowCapture::class);
        $this->requireCapability('admin.system.data-share.delete');

        if ($capture->deletePackage($path)) {
            $this->setStatus(__('Diagnostic package deleted.'), 'success');
        } else {
            $this->setStatus(__('Package not found or outside the diagnostic storage prefix.'), 'warning');
        }

        $this->diagnosticPackages = $capture->listPackages();
    }

    public function render(DataShareInstanceIdentityResolver $instances): View
    {
        return view('livewire.admin.system.data-share.index', [
            'instance' => $instances->current(),
            'canPublish' => $this->capabilityAllows('admin.system.data-share-offer.create'),
            'canManageOffers' => $this->capabilityAllows('admin.system.data-share-offer.manage'),
            'canReceive' => $this->capabilityAllows('admin.system.data-share-offer.accept'),
            'canPlan' => $this->capabilityAllows('admin.system.data-share-plan.review'),
            'canApply' => $this->capabilityAllows('admin.system.data-share-apply.execute'),
            'canExecuteMirror' => $this->capabilityAllows('admin.system.data-share-mirror.execute'),
            'canManageSettings' => $this->capabilityAllows('admin.system.data-share-settings.manage'),
            'canDelete' => $this->capabilityAllows('admin.system.data-share.delete'),
            'passwordConfirmationUrl' => Route::has('password.confirm') ? route('password.confirm') : null,
            'diskName' => app(DataShareSettings::class)->disk(),
            'pathPrefix' => app(DataShareSettings::class)->pathPrefix('data_share.path_prefix', 'data-share/diagnostics'),
        ]);
    }
}
