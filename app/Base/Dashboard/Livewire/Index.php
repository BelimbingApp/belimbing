<?php

namespace App\Base\Dashboard\Livewire;

use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\Services\DashboardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The personal dashboard: module-contributed widgets filtered by the
 * user's capabilities, in an order the user can edit.
 *
 * Widgets render as lazy Livewire components so first paint stays light and
 * each widget's queries run in their own request. Layout mutations persist
 * immediately through DashboardLayout (whole-list saves to prefs).
 */
class Index extends Component
{
    public bool $editing = false;

    public function toggleEditing(): void
    {
        $this->editing = ! $this->editing;
    }

    public function add(string $id): void
    {
        $layout = $this->layoutService();

        if (! $layout->visibleFor(Auth::user())->has($id)) {
            return;
        }

        $ids = $this->currentIds();
        $ids[] = $id;

        $layout->save(Auth::user(), $ids);
    }

    public function remove(string $id): void
    {
        $this->layoutService()->save(
            Auth::user(),
            array_values(array_diff($this->currentIds(), [$id])),
        );
    }

    public function moveUp(string $id): void
    {
        $this->move($id, -1);
    }

    public function moveDown(string $id): void
    {
        $this->move($id, 1);
    }

    public function resetLayout(): void
    {
        $this->layoutService()->reset(Auth::user());
    }

    public function reorder(string $id, int $position): void
    {
        $ids = $this->currentIds();
        $currentPosition = array_search($id, $ids, true);

        if ($currentPosition === false
            || $position < 0
            || $position >= count($ids)
            || $currentPosition === $position) {
            return;
        }

        array_splice($ids, $currentPosition, 1);
        array_splice($ids, $position, 0, [$id]);

        $this->layoutService()->save(Auth::user(), $ids);
    }

    public function render(): View
    {
        $layout = $this->layoutService();
        $user = Auth::user();

        $widgets = $layout->layoutFor($user);
        $widgetIds = array_map(fn (WidgetDefinition $widget): string => $widget->id, $widgets);

        return view('livewire.dashboard.index', [
            'widgets' => $widgets,
            'available' => $layout->visibleFor($user)->except($widgetIds)->values()->all(),
            'hasCustomLayout' => $layout->hasCustomLayout($user),
        ]);
    }

    private function move(string $id, int $offset): void
    {
        $widgets = $this->layoutService()->layoutFor(Auth::user());
        $ids = array_map(fn (WidgetDefinition $widget): string => $widget->id, $widgets);
        $index = array_search($id, $ids, true);

        if ($index === false) {
            return;
        }

        $target = $index;

        do {
            $target += $offset;

            if ($target < 0 || $target >= count($widgets)) {
                return;
            }
        } while ($widgets[$target]->size !== $widgets[$index]->size);

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->layoutService()->save(Auth::user(), $ids);
    }

    /**
     * @return list<string>
     */
    private function currentIds(): array
    {
        return array_map(
            fn (WidgetDefinition $widget): string => $widget->id,
            $this->layoutService()->layoutFor(Auth::user()),
        );
    }

    private function layoutService(): DashboardLayout
    {
        return app(DashboardLayout::class);
    }
}
