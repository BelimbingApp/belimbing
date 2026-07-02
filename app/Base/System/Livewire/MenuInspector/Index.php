<?php

namespace App\Base\System\Livewire\MenuInspector;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use App\Base\Menu\Services\MenuRegistryLoader;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    /** Rows rendered per page — bounds the diagnostic table's initial HTML. */
    private const PER_PAGE = 25;

    public string $search = '';

    public string $sourceFilter = 'all';

    public string $kindFilter = 'all';

    public function clearFilters(): void
    {
        $this->search = '';
        $this->sourceFilter = 'all';
        $this->kindFilter = 'all';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $registry = app(MenuRegistry::class);
        $checker = app(MenuAccessChecker::class);
        $user = Auth::user();

        // The registry is normally populated by the layout view composer. When
        // Livewire re-renders this component on an interaction, the singleton
        // may be empty if the composer has not run for this request.
        if ($registry->getAll()->isEmpty()) {
            app(MenuRegistryLoader::class)->ensureLoaded();
        }

        $items = $registry->getAll()
            ->map(fn (MenuItem $item) => $this->row($item, $checker, $user))
            ->values();

        $sources = $items->pluck('sourceModule')->filter()->unique()->sort()->values();

        $filtered = $items->filter(function (array $row): bool {
            if ($this->sourceFilter !== 'all' && $row['sourceModule'] !== $this->sourceFilter) {
                return false;
            }

            if ($this->kindFilter === 'core' && $row['isExtension']) {
                return false;
            }

            if ($this->kindFilter === 'extension' && ! $row['isExtension']) {
                return false;
            }

            if ($this->search === '') {
                return true;
            }

            $needle = mb_strtolower($this->search);

            return str_contains(mb_strtolower($row['id']), $needle)
                || str_contains(mb_strtolower((string) $row['label']), $needle)
                || str_contains(mb_strtolower((string) $row['permission']), $needle)
                || str_contains(mb_strtolower((string) $row['sourceFile']), $needle);
        })->sortBy('id')->values();

        $page = $this->getPage();
        $rows = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );

        return view('livewire.admin.system.menu-inspector.index', [
            'rows' => $rows,
            'totalCount' => $items->count(),
            'sources' => $sources,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(MenuItem $item, MenuAccessChecker $checker, ?Authenticatable $user): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'icon' => $item->icon,
            'parent' => $item->parent,
            'route' => $item->route,
            'url' => $item->url,
            'permission' => $item->permission,
            'condition' => $item->condition,
            'sourceModule' => $item->sourceModule,
            'sourceFile' => $item->sourceFile,
            'isExtension' => $item->isFromExtension(),
            'isContainer' => $item->isContainer(),
            'visibleToCurrentUser' => $user !== null && $checker->canView($item, $user),
        ];
    }
}
