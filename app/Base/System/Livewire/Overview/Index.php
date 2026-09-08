<?php

namespace App\Base\System\Livewire\Overview;

use App\Base\System\Services\SystemOverviewPageData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Operator landing for system diagnostics: one card per surface the actor
 * can see, with the headline count from that surface's existing page data.
 */
final class Index extends Component
{
    public function mount(SystemOverviewPageData $pageData): void
    {
        $user = auth()->user();
        abort_if($user === null, 401);
        abort_unless($pageData->actorCanView($user), 403);
    }

    public function render(SystemOverviewPageData $pageData): View
    {
        $user = auth()->user();
        abort_if($user === null, 401);

        return view('livewire.admin.system.overview.index', [
            'cards' => $pageData->cardsFor($user),
        ]);
    }
}
