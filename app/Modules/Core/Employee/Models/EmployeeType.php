<?php
namespace App\Modules\Core\Employee\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeType extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'employee_types';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'label',
        'is_system',
        'company_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get employees with this type.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'employee_type', 'code');
    }

    /**
     * Whether this type denotes a Agent.
     */
    public function isAgent(): bool
    {
        return $this->code === 'agent';
    }

    /**
     * Scope to system types (non-deletable by licensee).
     */
    public function scopeSystem($query): void
    {
        $query->where('is_system', true);
    }

    /**
     * Scope to custom types (licensee-managed).
     */
    public function scopeCustom($query): void
    {
        $query->where('is_system', false);
    }

    /**
     * Scope to global types (company_id null).
     */
    public function scopeGlobal($query): void
    {
        $query->whereNull('company_id');
    }

    /**
     * Find type by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }
}
