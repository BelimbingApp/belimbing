<?php

namespace App\Base\Dashboard;

use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\Services\WidgetDiscoveryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\LivewireManager;

/**
 * All registered dashboard widgets, keyed by id in discovery order.
 *
 * Discovery order defines the default dashboard order. Duplicate ids follow
 * the menu registry convention: last definition wins, enabling extension
 * override of a shipped widget.
 */
class WidgetRegistry
{
    /** @var Collection<string, WidgetDefinition>|null */
    protected ?Collection $widgets = null;

    public function __construct(
        protected WidgetDiscoveryService $discovery,
        protected LivewireManager $livewire,
    ) {}

    /**
     * @return Collection<string, WidgetDefinition>
     */
    public function all(): Collection
    {
        if ($this->widgets !== null) {
            return $this->widgets;
        }

        $widgets = collect();

        foreach ($this->discovery->discover() as $raw) {
            $definition = WidgetDefinition::fromArray($raw);

            if ($definition === null) {
                Log::warning('Dashboard widget definition skipped: missing id or component', [
                    'file' => $raw['_source'] ?? 'unknown',
                ]);

                continue;
            }

            if (! $this->livewire->exists($definition->component)) {
                Log::warning('Dashboard widget definition skipped: Livewire component is unavailable', [
                    'id' => $definition->id,
                    'component' => $definition->component,
                    'file' => $raw['_source'] ?? 'unknown',
                ]);

                continue;
            }

            if ($widgets->has($definition->id)) {
                Log::info('Dashboard widget overridden', [
                    'id' => $definition->id,
                    'source' => $raw['_source'] ?? 'unknown',
                ]);
            }

            $widgets[$definition->id] = $definition;
        }

        return $this->widgets = $widgets;
    }
}
