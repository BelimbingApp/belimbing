@props([
    'compact' => false,
    'showRole' => true,
    'showShortcut' => false,
])

<x-ai.dw-identity
    name="Lara"
    role="System DW"
    icon="heroicon-o-sparkles"
    :shortcut="$showShortcut ? 'Ctrl+K' : null"
    :compact="$compact"
    :show-role="$showRole"
    {{ $attributes }}
/>
