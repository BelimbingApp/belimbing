<?php

namespace App\Base\System\Livewire\Capabilities;

use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Authz\Capability\CapabilityInventoryRow;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Request;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every declared capability, its owning module, the roles that grant it and
 * how many principals hold those roles in this tenant.
 *
 * Read-only. Two row states carry the warnings an operator cannot get
 * elsewhere: a capability declared by two modules, and one the catalog
 * rejected — the latter is denied to everybody at runtime however many
 * principals hold a role that grants it.
 */
final class Index extends Component
{
    use WithPagination;

    /**
     * The installation declares a few hundred capabilities, which is more than
     * a page should ship at once: rendering them all put this over the 150 KB
     * page-weight budget, and a table nobody can read is not a diagnostic.
     */
    private const PER_PAGE = 50;

    public const VIEW_CAPABILITY = CapabilityInventory::VIEW;

    public string $search = '';

    public bool $problemsOnly = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProblemsOnly(): void
    {
        $this->resetPage();
    }

    public function render(CapabilityInventory $inventory): View
    {
        $all = $inventory->rows();
        $rows = $all;
        $needle = trim(mb_strtolower($this->search));

        if ($needle !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (CapabilityInventoryRow $row): bool => str_contains(mb_strtolower($row->capability), $needle)
                    || str_contains(mb_strtolower(implode(' ', $row->modules)), $needle),
            ));
        }

        if ($this->problemsOnly) {
            $rows = array_values(array_filter(
                $rows,
                static fn (CapabilityInventoryRow $row): bool => $row->conflicted || $row->rejectedReason !== null,
            ));
        }

        $page = max(1, (int) $this->getPage());
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($rows),
            self::PER_PAGE,
            $page,
            ['path' => Request::url(), 'pageName' => 'page'],
        );

        return view('livewire.admin.system.capabilities.index', [
            'rows' => $paginator,
            // Counted over the whole inventory, not the page: an operator must
            // see that something is rejected even while filtered elsewhere.
            'rejectedCount' => count(array_filter(
                $all,
                static fn (CapabilityInventoryRow $row): bool => $row->rejectedReason !== null,
            )),
        ]);
    }
}
