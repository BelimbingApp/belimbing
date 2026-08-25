<?php

namespace App\Base\Database\Livewire\Queries;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Base\Foundation\Livewire\Concerns\TogglesSort;
use App\Core\User\Models\Query;
use App\Core\User\Models\UserPin;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ChecksCapabilityAuthorization;
    use ResetsPaginationOnSearch;
    use TogglesSort;
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'updated_at';

    public string $sortDir = 'desc';

    private const SORTABLE = [
        'name' => 'user_database_queries.name',
        'description' => 'user_database_queries.description',
        'created_at' => 'user_database_queries.created_at',
        'updated_at' => 'user_database_queries.updated_at',
    ];

    public function sort(string $column): void
    {
        $this->toggleSort(
            column: $column,
            allowedColumns: self::SORTABLE,
            defaultDir: [
                'name' => 'asc',
                'description' => 'asc',
                'created_at' => 'desc',
                'updated_at' => 'desc',
            ],
        );
    }

    /**
     * Redirect to the Show page in "new" mode without persisting a record.
     *
     * The record is only created when the user explicitly saves.
     */
    public function createView(): void
    {
        if (! $this->checkCapability('admin.system.database-table.edit')) {
            return;
        }

        $this->redirect(route('admin.system.database-queries.show', '_new'), navigate: true);
    }

    /**
     * Delete a query owned by the current user.
     *
     * Also removes any user pins that reference this query's URL.
     *
     * @param  int  $id  The query ID to delete
     */
    public function deleteView(int $id): void
    {
        if (! $this->checkCapability('admin.system.database-table.edit')) {
            return;
        }

        $query = Query::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        UserPin::query()
            ->where('user_id', auth()->id())
            ->where('url', 'like', '%/database-queries/'.$query->slug)
            ->delete();

        $query->delete();
        $this->notify(__('Query deleted.'));
    }

    /**
     * Duplicate a query for the current user.
     *
     * Creates a copy with a freshly generated unique slug.
     *
     * @param  int  $id  The query ID to duplicate
     */
    public function duplicateView(int $id): void
    {
        if (! $this->checkCapability('admin.system.database-table.edit')) {
            return;
        }

        $source = Query::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $userId = auth()->id();

        Query::query()->create([
            'user_id' => $userId,
            'name' => $source->name,
            'slug' => Query::generateSlug($source->name, $userId),
            'sql_query' => $source->sql_query,
            'description' => $source->description,
            'icon' => $source->icon,
        ]);
        $this->notify(__('Query duplicated.'));
    }

    public function render(): View
    {
        $sortColumn = self::SORTABLE[$this->sortBy] ?? 'user_database_queries.updated_at';

        return view('livewire.admin.system.database-queries.index', [
            'canEdit' => $this->canEditQueries(),
            'views' => Query::query()
                ->where('user_id', auth()->id())
                ->when($this->search, function (Builder $q, $search): void {
                    $q->where(function (Builder $inner) use ($search): void {
                        $inner->where('user_database_queries.name', 'like', '%'.$search.'%')
                            ->orWhere('user_database_queries.description', 'like', '%'.$search.'%');
                    });
                })
                ->orderBy($sortColumn, $this->sortDir)
                ->orderByDesc('user_database_queries.id')
                ->paginate(25),
        ]);
    }

    private function canEditQueries(): bool
    {
        $user = auth()->user();

        return $user !== null
            && app(AuthorizationService::class)
                ->can(Actor::forUser($user), 'admin.system.database-table.edit')
                ->allowed;
    }
}
