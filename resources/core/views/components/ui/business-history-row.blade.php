@props([
    'id',
    'when',
    'action',
    'by',
])

<tr wire:key="business-history-{{ $id }}" {{ $attributes }}>
    <td class="whitespace-nowrap px-table-cell-x py-table-cell-y align-top text-sm text-muted">
        <x-ui.datetime :value="$when" />
    </td>
    <td class="px-table-cell-x py-table-cell-y align-top text-sm font-medium text-ink">
        {{ $action }}
    </td>
    <td class="px-table-cell-x py-table-cell-y align-top text-sm text-ink">
        {{ $by }}
    </td>
    <td class="min-w-64 px-table-cell-x py-table-cell-y align-top text-sm text-ink">
        {{ $slot }}
    </td>
</tr>
