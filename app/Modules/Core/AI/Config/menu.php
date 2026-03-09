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
        $item('ai.playground', 'DW Playground', 'heroicon-o-chat-bubble-left-right', 'ai', 10, 'admin.ai.playground'),
        $item('ai.providers', 'LLM Providers', 'heroicon-o-server-stack', 'ai', 20, 'admin.ai.providers'),
        $item('ai.tools', 'Tools', 'heroicon-o-wrench-screwdriver', 'ai', 30, 'admin.ai.tools'),
    ],
];
