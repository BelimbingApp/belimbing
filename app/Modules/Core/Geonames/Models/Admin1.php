<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Models;

use App\Base\Database\Exceptions\UnsupportedDatabaseDriverException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Admin1 extends Model
{
    /**
     * Build SQL to extract country ISO prefix from admin1 code (e.g. US.CA -> US).
     */
    public static function countryIsoSql(string $column = 'geonames_admin1.code'): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "SUBSTR($column, 1, INSTR($column, '.') - 1)",
            'mysql', 'mariadb' => "SUBSTRING_INDEX($column, '.', 1)",
            'pgsql' => "SPLIT_PART($column, '.', 1)",
            'sqlsrv' => "LEFT($column, CHARINDEX('.', $column + '.') - 1)",
            default => throw UnsupportedDatabaseDriverException::forOperation(
                driver: $driver,
                operation: self::class.'::countryIsoSql',
            ),
        };
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'geonames_admin1';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = ['code', 'name', 'alt_name'];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Extract the country ISO code from the code field (e.g., 'US.CA' → 'US').
     */
    public function getCountryIsoAttribute(): ?string
    {
        if (! $this->code) {
            return null;
        }

        return explode('.', $this->code)[0] ?? null;
    }

    /**
     * Scope: filter by country ISO code prefix.
     */
    public function scopeForCountry(Builder $query, string $iso): Builder
    {
        return $query->whereRaw('UPPER(code) LIKE ?', [strtoupper($iso).'.%']);
    }

    /**
     * Scope: join geonames_countries to add country_name to the result set.
     */
    public function scopeWithCountryName(Builder $query): Builder
    {
        return $query
            ->selectRaw('geonames_admin1.*, geonames_countries.country as country_name, geonames_countries.iso as country_iso')
            ->leftJoin('geonames_countries', function ($join) {
                $countryIsoSql = self::countryIsoSql('geonames_admin1.code');
                $join->whereRaw("geonames_countries.iso = $countryIsoSql");
            });
    }
}
