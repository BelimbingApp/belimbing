<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/*
 * Operations domain anchor.
 *
 * Declares the `operations` top-level bucket. Leaf modules under
 * app/Modules/Operation/* parent their items into this bucket. Lives at the
 * domain level (not in a leaf module) so disabling any single sub-module does
 * not orphan the bucket.
 */

return [
    'items' => [
        [
            'id' => 'operations',
            'label' => 'Operations',
            'icon' => 'heroicon-o-building-office',
        ],
    ],
];
