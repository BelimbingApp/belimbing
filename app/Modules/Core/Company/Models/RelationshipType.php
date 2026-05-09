<?php
namespace App\Modules\Core\Company\Models;

use App\Modules\Core\Company\Database\Factories\RelationshipTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelationshipType extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'company_relationship_types';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_external',
        'is_active',
        'metadata',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RelationshipTypeFactory
    {
        return new RelationshipTypeFactory;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_external' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get all company relationships using this type.
     */
    public function companyRelationships(): HasMany
    {
        return $this->hasMany(
            CompanyRelationship::class,
            'relationship_type_id'
        );
    }

    /**
     * Check if this relationship type allows external access.
     */
    public function allowsExternalAccess(): bool
    {
        return $this->is_external;
    }

    /**
     * Check if this relationship type is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Activate the relationship type.
     */
    public function activate(): bool
    {
        $this->is_active = true;

        return $this->save();
    }

    /**
     * Deactivate the relationship type.
     */
    public function deactivate(): bool
    {
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Scope a query to only include active relationship types.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to only include external relationship types.
     */
    public function scopeExternal($query): void
    {
        $query->where('is_external', true);
    }

    /**
     * Scope a query to only include internal relationship types.
     */
    public function scopeInternal($query): void
    {
        $query->where('is_external', false);
    }

    /**
     * Get a relationship type by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }
}
