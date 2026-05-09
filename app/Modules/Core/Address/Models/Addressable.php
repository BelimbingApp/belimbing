<?php
namespace App\Modules\Core\Address\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Addressable extends MorphPivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'addressables';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'address_id',
        'addressable_type',
        'addressable_id',
        'kind',
        'is_primary',
        'priority',
        'valid_from',
        'valid_to',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => 'array',
            'is_primary' => 'boolean',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the owning model (Company, Employee, etc).
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
