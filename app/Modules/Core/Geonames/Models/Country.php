<?php
namespace App\Modules\Core\Geonames\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geonames_countries';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'iso',
        'iso3',
        'iso_numeric',
        'country',
        'capital',
        'area',
        'population',
        'continent',
        'tld',
        'currency_code',
        'currency_name',
        'phone',
        'postal_code_format',
        'postal_code_regex',
        'languages',
        'geoname_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area' => 'float',
            'population' => 'integer',
            'geoname_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Query admin1 divisions for this country.
     *
     * Not a standard Eloquent relationship since the key is a code prefix.
     *
     * @return Builder<Admin1>
     */
    public function admin1s(): Builder
    {
        return Admin1::query()->forCountry($this->iso);
    }
}
