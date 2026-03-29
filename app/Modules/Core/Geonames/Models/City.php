<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $table = 'geonames_cities';

    protected $fillable = [
        'geoname_id',
        'name',
        'ascii_name',
        'alternate_names',
        'latitude',
        'longitude',
        'country_iso',
        'admin1_code',
        'population',
        'timezone',
        'modification_date',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'population' => 'integer',
            'modification_date' => 'date',
        ];
    }

    // Relationships to Country/Admin1 can be added if needed
}
