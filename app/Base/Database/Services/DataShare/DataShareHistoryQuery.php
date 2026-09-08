<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Models\DataShareEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read the Data Share events ledger for the History tab and CLI.
 */
class DataShareHistoryQuery
{
    /** @var array<string, list<string>> */
    public const array ACTION_CLASSES = [
        'offer' => [
            'offer_published',
            'offer_revoked',
            'offer_expired',
            'offer_exhausted',
            'offer_downloaded',
        ],
        'fetch' => [
            'offer_fetched',
            'fetch_failed',
            'received',
        ],
        'plan' => [
            'planned',
            'plan_failed',
        ],
        'apply' => [
            'applied',
            'apply_failed',
        ],
        'prune' => [
            'package_pruned',
        ],
        'export' => [
            'exported',
            'export_failed',
        ],
        'identity' => [
            'identity_changed',
        ],
        'failures' => [
            'fetch_failed',
            'plan_failed',
            'export_failed',
            'apply_failed',
        ],
    ];

    /**
     * @return Builder<DataShareEvent>
     */
    public function query(?string $actionClass = null): Builder
    {
        $query = DataShareEvent::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($actionClass === null || $actionClass === '' || $actionClass === 'all') {
            return $query;
        }

        if ($actionClass === 'failures') {
            return $query->where('action', 'like', '%_failed');
        }

        $actions = self::ACTION_CLASSES[$actionClass] ?? null;

        if ($actions === null) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('action', $actions);
    }

    public function paginate(?string $actionClass, int $perPage): LengthAwarePaginator
    {
        return $this->query($actionClass)->paginate($perPage);
    }

    /**
     * @return Collection<int, DataShareEvent>
     */
    public function all(?string $actionClass = null, ?int $limit = null): Collection
    {
        $query = $this->query($actionClass);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }
}
