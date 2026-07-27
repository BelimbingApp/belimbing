{{--
    Tab panel: individual tab content within an <x-ui.tabs> container.

    Props:
        id — Must match a tab ID in the parent <x-ui.tabs :tabs="..."> array.

    The panel reads Alpine state (activeTab, tabId, panelId) from the parent
    <x-ui.tabs> x-data scope. Do not use this component outside of <x-ui.tabs>.

    Usage: See <x-ui.tabs> for full example.
--}}
@aware(['tabsId'])

@props(['id'])

@php
    $tabsId = trim((string) $tabsId);

    throw_if($tabsId === '', InvalidArgumentException::class, 'The parent tabsId prop is required.');
@endphp

<div
    x-show="isActive('{{ $id }}')"
    x-cloak
    role="tabpanel"
    id="{{ $tabsId }}-panel-{{ $id }}"
    aria-labelledby="{{ $tabsId }}-tab-{{ $id }}"
    :tabindex="isActive('{{ $id }}') ? '0' : '-1'"
    {{ $attributes->class([]) }}
>
    {{ $slot }}
</div>
