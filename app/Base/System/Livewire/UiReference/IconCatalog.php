<?php

namespace App\Base\System\Livewire\UiReference;

use App\Base\Foundation\View\IconRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

// Lazy island: the full icon registry is ~145 SVG tiles. Rendering every
// <x-icon> eagerly made the Foundations section ~350 KB initial HTML.
#[Lazy]
class IconCatalog extends Component
{
    public function render(): View
    {
        return view('livewire.admin.system.ui-reference.icon-catalog', [
            'iconCatalog' => $this->iconCatalog(),
        ]);
    }

    public function placeholder(): View
    {
        return view('livewire.admin.system.ui-reference.icon-catalog-placeholder');
    }

    /**
     * Every registered icon, grouped by naming family, for the full catalog grid.
     *
     * @return list<array{label: string, note: string, icons: list<string>}>
     */
    private function iconCatalog(): array
    {
        $families = [
            'heroicon-o-' => ['label' => 'Heroicons — Outline', 'note' => 'heroicon-o-*, 24×24 stroke'],
            'heroicon-m-' => ['label' => 'Heroicons — Mini', 'note' => 'heroicon-m-*, 20×20 filled'],
            'heroicon-s-' => ['label' => 'Heroicons — Solid', 'note' => 'heroicon-s-*, 24×24 filled'],
            'mdi-' => ['label' => 'Material Design Icons', 'note' => 'mdi-*'],
            'codicon-' => ['label' => 'Codicons', 'note' => 'codicon-*'],
        ];

        $groups = array_map(static fn (array $family): array => [...$family, 'icons' => []], $families);
        $other = ['label' => 'Other / Brand', 'note' => 'Custom marks that do not follow a family prefix', 'icons' => []];

        foreach (IconRegistry::names() as $name) {
            $prefix = collect($families)->keys()->first(static fn (string $prefix): bool => str_starts_with($name, $prefix));

            if ($prefix === null) {
                $other['icons'][] = $name;

                continue;
            }

            $groups[$prefix]['icons'][] = $name;
        }

        $groups['other'] = $other;

        return array_values(array_filter($groups, static fn (array $group): bool => $group['icons'] !== []));
    }
}
