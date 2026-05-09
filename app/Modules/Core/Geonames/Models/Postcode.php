<?php
namespace App\Modules\Core\Geonames\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Postcode extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geonames_postcodes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'country_iso',
        'postcode',
        'place_name',
        'admin1Code',
        'admin_name1',
        'admin_code1',
        'admin_name2',
        'admin_code2',
        'admin_name3',
        'admin_code3',
        'latitude',
        'longitude',
        'accuracy',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Scope: join geonames_countries to add country_name to the result set.
     */
    public function scopeWithCountryName(Builder $query): Builder
    {
        return $query
            ->selectRaw('geonames_postcodes.*, geonames_countries.country as country_name')
            ->leftJoin('geonames_countries', 'geonames_postcodes.country_iso', '=', 'geonames_countries.iso');
    }

    /**
     * Get the country that this postcode belongs to.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_iso', 'iso');
    }

    /**
     * Get the admin1 division that this postcode belongs to.
     */
    public function admin1(): BelongsTo
    {
        return $this->belongsTo(Admin1::class, 'admin1Code', 'code');
    }
}
