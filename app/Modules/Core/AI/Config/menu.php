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
        $item('ai.playground', 'DW Playground', 'heroicon-o-chat-bubble-left-right', 'ai', 20, 'admin.ai.playground'),
        $item('ai.providers', 'AI Providers', 'heroicon-o-server-stack', 'ai', 30),
        $item('ai.providers.browse', 'Browse', 'heroicon-o-rectangle-stack', 'ai.providers', 10, 'admin.ai.providers.browse'),
        $item('ai.providers.connections', 'Connections', 'heroicon-o-link', 'ai.providers', 20, 'admin.ai.providers.connections'),
        $item('ai.tools', 'Tools', 'heroicon-o-wrench-screwdriver', 'ai', 40, 'admin.ai.tools'),
    ],
];
