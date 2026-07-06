<?php

namespace App\Base\Dashboard;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Base class for dashboard widgets.
 *
 * Widgets are self-contained Livewire components mounted lazily by the
 * dashboard page, so each widget's queries run in their own request and a
 * slow widget cannot block first paint. This base class provides the shared
 * skeleton placeholder shown until the widget streams in; subclasses own
 * their data, empty states, and card markup. A widget must degrade to an
 * inline empty state, never a page error.
 */
abstract class Widget extends Component
{
    public function placeholder(): View
    {
        return view('livewire.dashboard.widget-placeholder');
    }
}
