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
 *
 * The page lays out two independent lanes — wide widgets and the narrow
 * rail — rather than one grid, so a tall widget only pushes down the lane
 * it lives in. Ordering is therefore lane-local: a widget can only move
 * against others of its own size.
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
        $this->shift($id, -1);
    }

    public function moveDown(string $id): void
    {
        $this->shift($id, 1);
    }

    public function resetLayout(): void
    {
        $this->layoutService()->reset(Auth::user());
    }

    /**
     * Move a widget to $position within its own lane.
     *
     * A widget's lane is fixed by its declared size, so both the drag
     * handler and the move buttons speak lane coordinates: position is an
     * index among same-size widgets, not into the whole layout. The saved
     * list keeps its lane interleaving; only the order inside the moved
     * widget's lane changes.
     */
    public function reorder(string $id, int $position): void
    {
        $widgets = $this->layoutService()->layoutFor(Auth::user());
        $slots = $this->laneSlots($widgets, $id);
        $laneIds = array_map(fn (int $slot): string => $widgets[$slot]->id, $slots);
        $currentPosition = array_search($id, $laneIds, true);

        if ($currentPosition === false
            || $position < 0
            || $position >= count($laneIds)
            || $currentPosition === $position) {
            return;
        }

        array_splice($laneIds, $currentPosition, 1);
        array_splice($laneIds, $position, 0, [$id]);

        $ids = array_map(fn (WidgetDefinition $widget): string => $widget->id, $widgets);

        foreach ($slots as $offset => $slot) {
            $ids[$slot] = $laneIds[$offset];
        }

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
            'wide' => $this->lane($widgets, WidgetDefinition::SIZE_WIDE),
            'narrow' => $this->lane($widgets, WidgetDefinition::SIZE_NARROW),
            'available' => $layout->visibleFor($user)->except($widgetIds)->values()->all(),
            'hasCustomLayout' => $layout->hasCustomLayout($user),
        ]);
    }

    private function shift(string $id, int $offset): void
    {
        $widgets = $this->layoutService()->layoutFor(Auth::user());
        $laneIds = array_map(
            fn (int $slot): string => $widgets[$slot]->id,
            $this->laneSlots($widgets, $id),
        );
        $currentPosition = array_search($id, $laneIds, true);

        if ($currentPosition === false) {
            return;
        }

        $this->reorder($id, $currentPosition + $offset);
    }

    /**
     * The widgets of one lane, in layout order.
     *
     * @param  list<WidgetDefinition>  $widgets
     * @return list<WidgetDefinition>
     */
    private function lane(array $widgets, int $size): array
    {
        return array_values(array_filter(
            $widgets,
            fn (WidgetDefinition $widget): bool => $widget->size === $size,
        ));
    }

    /**
     * Layout positions of every widget sharing $id's lane, in order.
     *
     * Empty when the widget is not on the dashboard.
     *
     * @param  list<WidgetDefinition>  $widgets
     * @return list<int>
     */
    private function laneSlots(array $widgets, string $id): array
    {
        $lane = null;

        foreach ($widgets as $widget) {
            if ($widget->id === $id) {
                $lane = $widget->size;

                break;
            }
        }

        if ($lane === null) {
            return [];
        }

        $slots = [];

        foreach ($widgets as $index => $widget) {
            if ($widget->size === $lane) {
                $slots[] = $index;
            }
        }

        return $slots;
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
