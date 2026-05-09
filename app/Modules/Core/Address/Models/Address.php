<?php
namespace App\Modules\Core\Address\Models;

use App\Modules\Core\Address\Database\Factories\AddressFactory;
use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'addresses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'label',
        'phone',
        'line1',
        'line2',
        'line3',
        'locality',
        'postcode',
        'country_iso',
        'admin1Code',
        'rawInput',
        'source',
        'sourceRef',
        'parserVersion',
        'parseConfidence',
        'parsed_at',
        'normalized_at',
        'normalization_notes',
        'verificationStatus',
        'metadata',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AddressFactory
    {
        return new AddressFactory;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parseConfidence' => 'decimal:4',
            'parsed_at' => 'datetime',
            'normalized_at' => 'datetime',
            'normalization_notes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the Geonames country referenced by this address.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_iso', 'iso');
    }

    /**
     * Validation rules for address fields (shared by create form and inline-edit).
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string'],
            'line2' => ['nullable', 'string'],
            'line3' => ['nullable', 'string'],
            'locality' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'sourceRef' => ['nullable', 'string', 'max:255'],
            'rawInput' => ['nullable', 'string'],
        ];
    }

    /**
     * Get the Geonames admin1 referenced by this address.
     */
    public function admin1(): BelongsTo
    {
        return $this->belongsTo(Admin1::class, 'admin1Code', 'code');
    }
}
