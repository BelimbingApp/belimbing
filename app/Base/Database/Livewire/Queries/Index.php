<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Livewire\Queries;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\User\Models\Query;
use App\Modules\Core\User\Models\UserPin;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    /**
     * Redirect to the Show page in "new" mode without persisting a record.
     *
     * The record is only created when the user explicitly saves.
     */
    public function createView(): void
    {
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
        $query = Query::query()
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        UserPin::query()
            ->where('user_id', auth()->id())
            ->where('url', 'like', '%/database-queries/'.$query->slug)
            ->delete();

        $query->delete();
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
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.admin.system.database-queries.index', [
            'views' => Query::query()
                ->where('user_id', auth()->id())
                ->when($this->search, function ($q, $search): void {
                    $q->where(function ($q) use ($search): void {
                        $q->where('name', 'like', '%'.$search.'%')
                            ->orWhere('description', 'like', '%'.$search.'%');
                    });
                })
                ->orderByDesc('updated_at')
                ->paginate(25),
        ]);
    }
}
