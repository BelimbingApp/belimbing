@props([
    'caption' => null,
    'empty' => false,
    'emptyMessage' => null,
])

<x-ui.table
    :caption="$caption ?? __('Business history')"
    container="bordered"
    size="sm"
    :empty="$empty"
    :empty-colspan="4"
    :empty-message="$emptyMessage ?? __('No activity yet.')"
    {{ $attributes }}
>
    <x-slot:head>
        <tr>
            <x-ui.th class="whitespace-nowrap">{{ __('When') }}</x-ui.th>
            <x-ui.th>{{ __('Action') }}</x-ui.th>
            <x-ui.th>{{ __('By') }}</x-ui.th>
            <x-ui.th>{{ __('Details') }}</x-ui.th>
        </tr>
    </x-slot:head>

    {{ $slot }}
</x-ui.table>
