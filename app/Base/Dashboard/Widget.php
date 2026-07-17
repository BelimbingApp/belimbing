<?php

namespace App\Base\Dashboard;

use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use LogicException;
use Throwable;

/**
 * Base class for dashboard widgets.
 *
 * Widgets are self-contained Livewire components mounted lazily by the
 * dashboard page, so each widget's queries run in their own request and a
 * slow widget cannot block first paint. This base class provides the shared
 * skeleton placeholder shown until the widget streams in; subclasses own
 * their data, empty states, and card markup.
 *
 * A widget must degrade to an inline failure card, never a page error:
 * subclasses implement `content()` and the base render catches anything it
 * throws — a broken widget (bad query, missing class after a partial
 * cross-repo update) reports the exception and renders the fallback card
 * while the rest of the dashboard stays alive. `render()` intentionally
 * remains overridable while independently deployed distribution bundles
 * migrate from the legacy render contract; legacy overrides bypass this
 * guard and must own their own failure handling.
 */
abstract class Widget extends Component
{
    public function placeholder(): View
    {
        return view('livewire.dashboard.widget-placeholder');
    }

    public function render(): View
    {
        try {
            if (! method_exists($this, 'content')) {
                throw new LogicException(static::class.' must implement content().');
            }

            return app()->call(Closure::fromCallable([$this, 'content']));
        } catch (Throwable $exception) {
            report($exception);

            return view('livewire.dashboard.widget-failed');
        }
    }
}
