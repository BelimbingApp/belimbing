<?php

namespace App\Base\System\Livewire\Capabilities;

use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Authz\Capability\CapabilityInventoryRow;
use Illuminate\Contracts\View\View;
use Livewire\Component;

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
    public const VIEW_CAPABILITY = CapabilityInventory::VIEW;

    public string $search = '';

    public bool $problemsOnly = false;

    public function render(CapabilityInventory $inventory): View
    {
        $rows = $inventory->rows();
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

        return view('livewire.admin.system.capabilities.index', [
            'rows' => $rows,
            'rejectedCount' => count(array_filter(
                $inventory->rows(),
                static fn (CapabilityInventoryRow $row): bool => $row->rejectedReason !== null,
            )),
        ]);
    }
}
