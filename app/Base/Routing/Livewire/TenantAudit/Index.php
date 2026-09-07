<?php

namespace App\Base\Routing\Livewire\TenantAudit;

use App\Base\Routing\Services\TenantAuditPageData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only operator view of domain-route middleware audit findings and
 * Domain module ownership collisions. Never mutates application state.
 */
class Index extends Component
{
    public function render(TenantAuditPageData $pageData): View
    {
        return view('livewire.admin.system.tenant-audit.index', [
            'routeRows' => $pageData->routeRows(),
            'ownership' => $pageData->ownershipReport(),
            'missRows' => $pageData->missRows(),
            'resolutionMix' => $pageData->resolutionMix(),
        ]);
    }
}
