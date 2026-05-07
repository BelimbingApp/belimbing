<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
    ],
];
