<?php

namespace App\Base\Database\Livewire\DataShare;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataSharePlan;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataShareDirectionPolicy;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\DataShareOfferFetcher;
use App\Base\Database\Services\DataShare\DataSharePackageApplier;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareSettings;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Database\Services\DataShare\DiagnosticRowCapture;
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

    public function reviewOffer(): void
    {
        $this->requireCapability('admin.system.data-share-offer.accept');

        try {
            $offer = DataShareTransferOfferBundle::fromJson($this->offerBundle);
            app(DataShareDirectionPolicy::class)
                ->assertAllowed($offer->source, app(DataShareInstanceIdentityResolver::class)->current());
            app(DataShareScopeCatalog::class)->scope($offer->scope);

            if ($offer->isExpired()) {
                throw DataShareTransportException::expiredTransferOffer();
            }

            $this->offerEndpoint = $offer->endpoint;
            $this->offerEndpoints = $offer->endpoints;
            $this->reviewedOffer = [
                'offer_id' => $offer->offerId,
                'source_id' => $offer->source->id,
                'source_name' => $offer->source->name,
                'source_role' => $offer->source->role->value,
                'scope' => $offer->scope,
                'package_id' => $offer->packageId,
                'sha256' => $offer->packageSha256,
                'bytes' => $offer->bytes,
                'counts' => $offer->counts,
                'expires_at' => $offer->expiresAt,
            ];
            $this->setStatus(__('Offer from :source is ready for review. Fetching it will not plan or apply data.', [
                'source' => $offer->source->name,
            ]), 'success');
        } catch (Throwable $e) {
            $this->clearReviewedOffer();
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function fetchOffer(DataShareOfferFetcher $fetcher): void
    {
        $this->requireCapability('admin.system.data-share-offer.accept');

        try {
            $offer = DataShareTransferOfferBundle::fromJson($this->offerBundle);

            if ($this->offerEndpoint !== '') {
                $offer = $offer->usingEndpoint($this->offerEndpoint);
            }

            $receipt = $fetcher->fetch($offer);
            $this->setStatus(__('Package :package was fetched and verified into Incoming with SHA-256 :hash.', [
                'package' => $receipt->package_id,
                'hash' => $receipt->package_sha256,
            ]), 'success');
            $this->offerBundle = '';
            $this->clearReviewedOffer();
            $this->refreshOperations();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function revokeOffer(int $offerId, DataShareTransferOfferManager $manager): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $manager->revoke(DataShareTransferOffer::query()->findOrFail($offerId));
        $this->setStatus(__('Transfer offer revoked.'), 'success');
        $this->refreshOperations();
    }

    public function copyOfferBundle(int $offerId, DataShareTransferOfferManager $manager): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');

        try {
            $offer = DataShareTransferOffer::query()->findOrFail($offerId);
            $bundle = $manager->bundleFor($offer);

            $this->dispatch('data-share-bundle-ready', bundle: $bundle->toJson(), offerId: $offer->offer_id);
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
            $this->refreshOperations();
        }
    }

    public function offerBundleCopied(string $offerId): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $this->setStatus(__('Transfer offer :offer bundle copied. Paste it on the target instance.', [
            'offer' => $offerId,
        ]), 'success');
    }

    public function offerBundleCopyFailed(): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $this->setStatus(__('The transfer offer bundle could not be copied. Check browser clipboard permission and try again.'), 'danger');
    }

    public function clearPublishedOfferBundle(): void
    {
        $this->publishedOfferBundle = null;
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
            'canManageSettings' => $this->capabilityAllows('admin.system.data-share-settings.manage'),
            'canDelete' => $this->capabilityAllows('admin.system.data-share.delete'),
            'passwordConfirmationUrl' => Route::has('password.confirm') ? route('password.confirm') : null,
            'diskName' => app(DataShareSettings::class)->disk(),
            'pathPrefix' => app(DataShareSettings::class)->pathPrefix('data_share.path_prefix', 'data-share/diagnostics'),
        ]);
    }

    private function refreshOperations(): void
    {
        $this->incoming = DataShareReceipt::query()
            ->with('plans')
            ->latest('received_at')
            ->limit(50)
            ->get()
            ->map(function (DataShareReceipt $receipt): array {
                $plan = $receipt->plans->sortByDesc('planned_at')->first();

                return [
                    'id' => $receipt->id,
                    'package_id' => $receipt->package_id,
                    'sha256' => $receipt->package_sha256,
                    'source_instance_id' => $receipt->source_instance_id,
                    'scope_name' => $receipt->scope_name,
                    'offer_id' => $receipt->offer_id,
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
        $offerManager = app(DataShareTransferOfferManager::class);
        $offers = DataShareTransferOffer::query()
            ->latest()
            ->limit(50)
            ->get();
        $offers->each(fn (DataShareTransferOffer $offer) => $offerManager->refreshAvailability($offer));
        $this->offers = $offers
            ->map(fn (DataShareTransferOffer $offer): array => [
                'id' => $offer->id,
                'offer_id' => $offer->offer_id,
                'package_id' => $offer->package_id,
                'package_sha256' => $offer->package_sha256,
                'source_instance_id' => $offer->source_instance_id,
                'source_role' => $offer->source_role,
                'scope_name' => $offer->scope_name,
                'bytes' => $offer->bytes,
                'counts' => $offer->metadata['counts'] ?? [],
                'status' => $offer->status,
                'expires_at' => $offer->expires_at,
                'revoked_at' => $offer->revoked_at,
                'download_count' => $offer->download_count,
                'max_downloads' => $offer->max_downloads,
                'last_downloaded_at' => $offer->last_downloaded_at,
                'updated_at' => $offer->updated_at,
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function scopeRows(DataShareScopeCatalog $catalog): array
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
                    'shareable' => $table->primaryKeyColumns !== [],
                ], $scope->tables),
            ];
        }, $catalog->scopes()));

        usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $rows;
    }

    private function validateShareSelection(): void
    {
        $this->validate([
            'scopeName' => ['required', 'string'],
            'selectedTables' => ['required', 'array', 'min:1'],
            'selectedTables.*' => ['required', 'string'],
            'maxDownloads' => ['required', 'integer', 'min:1', 'max:'.DataShareTransferOfferManager::MAX_DOWNLOADS],
        ]);
    }

    public function updatedScopeName(): void
    {
        $this->sharePreview = null;
        $this->clearPublishedOfferBundle();
        $this->selectEntireScope();
    }

    public function updatedSelectedTables(): void
    {
        $this->sharePreview = null;
        $this->clearPublishedOfferBundle();
    }

    public function updatedOfferBundle(): void
    {
        $this->clearReviewedOffer();
    }

    public function updatedOfferEndpoint(): void
    {
        // Route selection does not change immutable package identity.
    }

    public function selectEntireScope(): void
    {
        $scope = collect($this->scopes)->firstWhere('name', $this->scopeName);
        $this->selectedTables = collect($scope['tables'] ?? [])
            ->where('shareable', true)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function clearReviewedOffer(): void
    {
        $this->offerEndpoint = '';
        $this->offerEndpoints = [];
        $this->reviewedOffer = null;
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
