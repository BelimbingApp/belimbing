<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Models\BridgeReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;

class BridgePackageRetention
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgeEventRecorder $events,
        private readonly BridgeSettings $settings,
    ) {}

    /**
     * @return array{
     *     commit: bool,
     *     include_unapplied: bool,
     *     candidates: list<array{path: string, category: string, reason: string, receipt_id: int|null}>,
     *     deleted: list<string>
     * }
     */
    public function prune(bool $commit = false, bool $includeUnapplied = false): array
    {
        $disk = $this->storage->disk();
        $candidates = [
            ...$this->incomingCandidates($disk, $includeUnapplied),
            ...$this->receivingCandidates($disk),
            ...$this->outgoingCandidates($disk, $includeUnapplied),
        ];
        $deleted = [];

        if ($commit) {
            foreach ($candidates as $candidate) {
                if (! $disk->delete($candidate['path'])) {
                    continue;
                }

                $deleted[] = $candidate['path'];

                if ($candidate['receipt_id'] !== null) {
                    $this->recordReceiptDeletion($candidate['receipt_id'], $candidate);
                }
            }
        }

        return [
            'commit' => $commit,
            'include_unapplied' => $includeUnapplied,
            'candidates' => $candidates,
            'deleted' => $deleted,
        ];
    }

    /** @return list<array{path: string, category: string, reason: string, receipt_id: int|null}> */
    private function incomingCandidates(Filesystem $disk, bool $includeUnapplied): array
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays(max(
            1,
            $this->settings->integer('bridge.transfer_limits.incoming_retention_days', 14, 1, 3650),
        ));
        $prefix = $this->settings->pathPrefix('bridge.incoming_path_prefix', 'bridge/incoming');
        $receipts = BridgeReceipt::query()
            ->whereIn('package_path', $disk->allFiles($prefix))
            ->get()
            ->keyBy('package_path');
        $candidates = [];

        foreach ($disk->allFiles($prefix) as $path) {
            $receipt = $receipts->get($path);
            $oldEnough = $receipt === null
                ? $this->lastModifiedBefore($disk, $path, $cutoff)
                : $receipt->received_at->lessThanOrEqualTo($cutoff);

            if (! $oldEnough) {
                continue;
            }

            if ($receipt?->status === 'applied') {
                $candidates[] = $this->candidate($path, 'incoming', 'applied-retention-expired', $receipt->id);

                continue;
            }

            if ($includeUnapplied) {
                $candidates[] = $this->candidate(
                    $path,
                    'incoming',
                    $receipt === null ? 'orphaned-explicit' : 'unapplied-explicit',
                    $receipt?->id,
                );
            }
        }

        return $candidates;
    }

    /** @return list<array{path: string, category: string, reason: string, receipt_id: int|null}> */
    private function receivingCandidates(Filesystem $disk): array
    {
        $cutoff = CarbonImmutable::now('UTC')->subHours(max(
            1,
            $this->settings->integer('bridge.transfer_limits.receiving_retention_hours', 24, 1, 720),
        ));
        $prefix = $this->settings->pathPrefix('bridge.receiving_path_prefix', 'bridge/receiving');

        return array_values(array_map(
            fn (string $path): array => $this->candidate($path, 'receiving', 'abandoned-upload', null),
            array_filter(
                $disk->allFiles($prefix),
                fn (string $path): bool => $this->lastModifiedBefore($disk, $path, $cutoff),
            ),
        ));
    }

    /** @return list<array{path: string, category: string, reason: string, receipt_id: int|null}> */
    private function outgoingCandidates(Filesystem $disk, bool $includeUnapplied): array
    {
        if (! $includeUnapplied) {
            return [];
        }

        $cutoff = CarbonImmutable::now('UTC')->subDays(max(
            1,
            $this->settings->integer('bridge.transfer_limits.incoming_retention_days', 14, 1, 3650),
        ));
        $prefix = $this->settings->pathPrefix('bridge.outgoing_path_prefix', 'bridge/outgoing');

        return array_values(array_map(
            fn (string $path): array => $this->candidate($path, 'outgoing', 'source-copy-explicit', null),
            array_filter(
                $disk->allFiles($prefix),
                fn (string $path): bool => $this->lastModifiedBefore($disk, $path, $cutoff),
            ),
        ));
    }

    /** @return array{path: string, category: string, reason: string, receipt_id: int|null} */
    private function candidate(string $path, string $category, string $reason, ?int $receiptId): array
    {
        return [
            'path' => $path,
            'category' => $category,
            'reason' => $reason,
            'receipt_id' => $receiptId,
        ];
    }

    private function lastModifiedBefore(Filesystem $disk, string $path, CarbonImmutable $cutoff): bool
    {
        try {
            return $disk->lastModified($path) <= $cutoff->timestamp;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{path: string, category: string, reason: string, receipt_id: int|null} $candidate */
    private function recordReceiptDeletion(int $receiptId, array $candidate): void
    {
        $receipt = BridgeReceipt::query()->find($receiptId);

        if ($receipt === null) {
            return;
        }

        $deletedAt = CarbonImmutable::now('UTC')->toIso8601String();
        $receipt->forceFill([
            'metadata' => [
                ...(array) $receipt->metadata,
                'package_retained' => false,
                'package_deleted_at' => $deletedAt,
                'package_deletion_reason' => $candidate['reason'],
            ],
        ])->save();
        $this->events->recordReceipt('package_pruned', $receipt, [
            'path' => $candidate['path'],
            'reason' => $candidate['reason'],
        ]);
    }
}
