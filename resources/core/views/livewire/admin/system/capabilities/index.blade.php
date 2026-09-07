<div class="space-y-section-gap">
    <x-slot name="title">{{ __('Capabilities') }}</x-slot>

    <x-ui.page-header
        :title="__('Capabilities')"
        :subtitle="__('Every capability declared by a module, the roles that grant it, and how many principals hold those roles in this tenant.')"
    />

    @if ($rejectedCount > 0)
        {{-- The headline an operator needs: these are declared and do not
             exist, so nobody can use them however they are granted. --}}
        <x-ui.alert variant="warning">
            {{ trans_choice(
                '{1} :count declared capability is rejected by the registry and denied to everyone.|[2,*] :count declared capabilities are rejected by the registry and denied to everyone.',
                $rejectedCount,
                ['count' => $rejectedCount],
            ) }}
        </x-ui.alert>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <x-ui.input type="search" wire:model.live.debounce.300ms="search" :placeholder="__('Filter by capability or module')" />
        <x-ui.button type="button" wire:click="$toggle('problemsOnly')" :variant="$problemsOnly ? 'primary' : 'secondary'">
            {{ __('Problems only') }}
        </x-ui.button>
    </div>

    @if ($rows->isEmpty())
        <x-ui.alert variant="info">{{ __('No capability matches that filter.') }}</x-ui.alert>
    @else
        <x-ui.card>
            <x-ui.table container="flush" :caption="__('Declared capabilities, their modules, granting roles and holders in this tenant')">
                <x-slot name="head">
                    <tr>
                        <x-ui.th>{{ __('Capability') }}</x-ui.th>
                        <x-ui.th>{{ __('Declared by') }}</x-ui.th>
                        <x-ui.th>{{ __('Granting roles') }}</x-ui.th>
                        <x-ui.th>{{ __('Holders in this tenant') }}</x-ui.th>
                    </tr>
                </x-slot>
                <x-slot name="body">
                    @foreach ($rows as $row)
                        <tr wire:key="cap-{{ $row->capability }}">
                            <td class="px-table-cell-x py-table-cell-y text-sm text-ink">
                                <span class="font-mono">{{ $row->capability }}</span>
                                @if ($row->rejectedReason !== null)
                                    <x-ui.badge variant="danger">{{ __('Rejected') }}</x-ui.badge>
                                    <span class="text-muted">{{ $row->rejectedReason }}</span>
                                @endif
                                @if ($row->conflicted)
                                    <x-ui.badge variant="warning">{{ __('Declared twice') }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ implode(', ', $row->modules) }}</td>
                            <td class="px-table-cell-x py-table-cell-y text-sm text-muted">
                                {{ $row->roles === [] ? __('None') : implode(', ', $row->roles) }}
                            </td>
                            <td class="px-table-cell-x py-table-cell-y text-sm tabular-nums">
                                @if ($row->holders === null)
                                    {{-- Not zero: the capability does not exist, so no
                                         number about it would be true. --}}
                                    <span class="text-muted">{{ __('Not applicable') }}</span>
                                @else
                                    {{ $row->holders }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-slot>
            </x-ui.table>

            <div class="mt-4">{{ $rows->links() }}</div>
        </x-ui.card>
    @endif
</div>
