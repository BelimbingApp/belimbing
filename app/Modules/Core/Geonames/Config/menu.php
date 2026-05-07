<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.geonames',
            'label' => 'Geonames',
            'icon' => 'heroicon-o-globe-alt',
            'permission' => 'admin.geonames.list',
            'parent' => 'admin',
        ],
        [
            'id' => 'admin.geonames.country',
            'label' => 'Countries',
            'icon' => 'heroicon-o-flag',
            'route' => 'admin.geonames.countries.index',
            'parent' => 'admin.geonames',
        ],
        [
            'id' => 'admin.geonames.admin1-division',
            'label' => 'Admin1 Divisions',
            'icon' => 'heroicon-o-map',
            'route' => 'admin.geonames.admin1.index',
            'parent' => 'admin.geonames',
        ],
        [
            'id' => 'admin.geonames.postcode',
            'label' => 'Postcodes',
            'icon' => 'heroicon-o-map-pin',
            'route' => 'admin.geonames.postcodes.index',
            'parent' => 'admin.geonames',
        ],
    ],
];
