<?php
return static function (
    string $id,
    string $label,
    string $icon,
    ?string $parent = null,
    ?string $route = null,
    ?string $permission = null,
    ?string $condition = null,
): array {
    $row = [
        'id' => $id,
        'label' => $label,
        'icon' => $icon,
    ];

    if ($parent !== null) {
        $row['parent'] = $parent;
    }

    if ($route !== null) {
        $row['route'] = $route;
    }

    if ($permission !== null) {
        $row['permission'] = $permission;
    }

    if ($condition !== null) {
        $row['condition'] = $condition;
    }

    return $row;
};
