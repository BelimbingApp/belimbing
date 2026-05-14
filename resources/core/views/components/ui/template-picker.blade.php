@props([
    'title' => __('Templates'),
    'templates' => [],
    'selectedKey' => '',
    'showAll' => true,
    'selectAction' => '',
    'uploadAction' => null,
])

<x-ui.card>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h2 class="text-base font-semibold text-ink">{{ $title }}</h2>
            @if($selectedKey !== '')
                <p class="mt-1 text-xs text-muted">{{ __('Click the selected row again to clear it and show all templates.') }}</p>
            @endif
        </div>
        @if($uploadAction)
            <x-ui.button type="button" variant="secondary" wire:click="{{ $uploadAction }}">
                {{ __('Upload Template') }}
            </x-ui.button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-2xl border border-border-default">
        <table class="min-w-full divide-y divide-border-default text-sm">
            <thead class="bg-surface-subtle/80">
                <tr>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Template') }}</th>
                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Best for') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-default bg-surface-card">
                @foreach ($templates as $template)
                    @if ($showAll || $selectedKey === $template['key'])
                        <tr class="cursor-pointer border-l-4 transition hover:bg-surface-subtle {{ $selectedKey === $template['key'] ? 'border-accent bg-surface-subtle/70' : 'border-transparent' }}" wire:click="{{ $selectAction }}('{{ $template['key'] }}')" wire:key="template-picker-{{ $selectAction }}-{{ $template['key'] }}">
                            <td class="px-table-cell-x py-2.5 align-top">
                                <div class="font-medium text-ink">{{ $template['name'] }}</div>
                                <p class="mt-0.5 text-xs text-muted">{{ $template['summary'] }}</p>
                            </td>
                            <td class="px-table-cell-x py-2.5 align-top text-xs text-muted">{{ $template['best_for'] }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>
</x-ui.card>
