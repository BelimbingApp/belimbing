<?php

/*
 * Commerce domain anchor.
 *
 * Declares the `commerce` top-level bucket. Leaf modules under
 * app/Modules/Commerce/* and extension packages under extensions/* parent
 * their items into this bucket. Lives at the domain level (not in a leaf
 * module) so disabling any single sub-module does not orphan the bucket.
 */

return [
    'items' => [
        [
            'id' => 'commerce',
            'label' => 'Commerce',
            'icon' => 'heroicon-o-shopping-bag',
        ],
        [
            'id' => 'commerce.reports',
            'label' => 'Reports',
            'icon' => 'heroicon-o-chart-bar',
            'parent' => 'commerce',
        ],
        [
            'id' => 'commerce.sales',
            'label' => 'Sales',
            'icon' => 'heroicon-o-banknotes',
            'parent' => 'commerce',
        ],
    ],
];
