<?php
namespace App\Modules\Core\Company\Models;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'company_departments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'department_type_id',
        'head_id',
        'status',
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
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the company that this department belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the department type.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DepartmentType::class, 'department_type_id');
    }

    /**
     * Get the department head (employee who leads this department).
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_id');
    }

    /**
     * Get all employees in this department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }

    /**
     * Scope a query to only include active departments.
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Scope a query to filter by department type code.
     *
     * @param  string  $typeCode  Department type code to filter by
     */
    public function scopeOfType($query, string $typeCode): void
    {
        $query->whereHas('type', function ($q) use ($typeCode): void {
            $q->where('code', $typeCode);
        });
    }

    /**
     * Scope a query to filter by category.
     *
     * @param  string  $category  Department category to filter by
     */
    public function scopeCategory($query, string $category): void
    {
        $query->whereHas('type', function ($q) use ($category): void {
            $q->where('category', $category);
        });
    }
}
