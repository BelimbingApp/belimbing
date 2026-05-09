<?php
namespace App\Modules\Core\Company\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegalEntityType extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'company_legal_entity_types';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get all companies using this legal entity type.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'legal_entity_type_id');
    }

    /**
     * Check if this legal entity type is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Activate the legal entity type.
     */
    public function activate(): bool
    {
        $this->is_active = true;

        return $this->save();
    }

    /**
     * Deactivate the legal entity type.
     */
    public function deactivate(): bool
    {
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Scope a query to only include active legal entity types.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get a legal entity type by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }
}
