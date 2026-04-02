<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

$item = static function (
    string $id,
    string $label,
    string $icon,
    string $parent,
    int $position,
    ?string $route = null,
): array {
    return array_filter([
        'id' => $id,
        'label' => $label,
        'icon' => $icon,
        'route' => $route,
        'parent' => $parent,
        'position' => $position,
    ], static fn (mixed $value): bool => $value !== null);
};

return [
    'items' => [
        $item('ai', 'AI', 'heroicon-o-cpu-chip', 'admin', 200),
        $item('ai.lara', 'Lara', 'heroicon-o-sparkles', 'ai', 10, 'admin.setup.lara'),
        $item('ai.kodi', 'Kodi', 'heroicon-o-code-bracket', 'ai', 15, 'admin.setup.kodi'),
        $item('ai.playground', 'Agent Playground', 'heroicon-o-chat-bubble-left-right', 'ai', 20, 'admin.ai.playground'),
        $item('ai.providers', 'AI Providers', 'heroicon-o-server-stack', 'ai', 30, 'admin.ai.providers'),
        $item('ai.tools', 'Tools', 'heroicon-o-wrench-screwdriver', 'ai', 40, 'admin.ai.tools'),
        $item('ai.control-plane', 'Control Plane', 'heroicon-o-adjustments-horizontal', 'ai', 50, 'admin.ai.control-plane'),
    ],
];
