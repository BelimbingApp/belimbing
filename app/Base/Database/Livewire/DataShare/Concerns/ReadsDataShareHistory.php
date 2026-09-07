<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\Models\DataShareEvent;
use App\Base\Database\Services\DataShare\DataShareHistoryQuery;
use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;
use App\Base\Tenancy\Services\PlatformOperatorTenantAccess;
use App\Core\User\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

trait ReadsDataShareHistory
{
    use SelectsPerPage;
    use WithPagination;

    #[Url]
    public string $historyActionClass = '';

    public function updatedHistoryActionClass(): void
    {
        $this->resetPage();
    }

    protected function defaultPerPage(): int
    {
        return 25;
    }

    protected function historyAvailableToCurrentTenant(): bool
    {
        return app(PlatformOperatorTenantAccess::class)->allows();
    }

    /**
     * @return LengthAwarePaginator<int, DataShareEvent>
     */
    protected function historyEvents(DataShareHistoryQuery $history): LengthAwarePaginator
    {
        if (! $this->historyAvailableToCurrentTenant()) {
            return new Paginator([], 0, $this->perPage);
        }

        return $history->paginate($this->historyActionClass !== '' ? $this->historyActionClass : null, $this->perPage);
    }

    /**
     * @param  LengthAwarePaginator<int, DataShareEvent>  $events
     * @return array<int, string>
     */
    protected function historyActorNames(LengthAwarePaginator $events): array
    {
        $ids = Collection::make($events->items())
            ->pluck('actor_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
