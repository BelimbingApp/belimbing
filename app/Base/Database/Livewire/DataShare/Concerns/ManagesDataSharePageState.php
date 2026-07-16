<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;

trait ManagesDataSharePageState
{
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

    public function selectEntireScope(): void
    {
        $scope = collect($this->scopes)->firstWhere('name', $this->scopeName);
        $this->selectedTables = collect($scope['tables'] ?? [])
            ->where('shareable', true)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function setStatus(string $message, string $variant): void
    {
        $this->statusMessage = $message;
        $this->statusVariant = $variant;
    }
}
