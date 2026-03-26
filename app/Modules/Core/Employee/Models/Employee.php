<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Models;

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Address\Models\Addressable;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
use App\Modules\Core\Employee\Concerns\ManagesSystemAgents;
use App\Modules\Core\Employee\Database\Factories\EmployeeFactory;
use App\Modules\Core\Employee\Exceptions\SystemEmployeeDeletionException;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Employee extends Model
{
    use HasFactory;
    use ManagesSystemAgents;

    /**
     * The well-known ID for Lara, BLB's system orchestrator Agent.
     *
     * Lara is provisioned at install time and cannot be deleted.
     * Mirrors the Licensee pattern (Company::LICENSEE_ID).
     */
    public const LARA_ID = 1;

    /**
     * The well-known ID for Kodi, BLB's system developer Agent.
     *
     * Kodi is provisioned at install time and cannot be deleted.
     */
    public const KODI_ID = 2;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): EmployeeFactory
    {
        return new EmployeeFactory;
    }

    /**
     * Boot the model.
     *
     * Prevents deletion of system Agents (Lara, Kodi).
     */
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Employee $employee): void {
            if ($employee->isSystemAgent()) {
                throw new SystemEmployeeDeletionException($employee->id);
            }
        });
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'employees';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'department_id',
        'supervisor_id',
        'employee_number',
        'full_name',
        'short_name',
        'designation',
        'employee_type',
        'job_description',
        'email',
        'mobile_number',
        'status',
        'employment_start',
        'employment_end',
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
            'employment_start' => 'date',
            'employment_end' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the employee type (by code).
     */
    public function employeeType(): BelongsTo
    {
        return $this->belongsTo(EmployeeType::class, 'employee_type', 'code');
    }

    /**
     * Get the company that the employee belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the department that the employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * Get the user associated with the employee.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'employee_id');
    }

    /**
     * Get the supervisor (another employee).
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    /**
     * Get subordinates (employees who report to this employee).
     */
    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'supervisor_id');
    }

    /**
     * Get addresses associated with the employee.
     */
    public function addresses(): MorphToMany
    {
        return $this->morphToMany(Address::class, 'addressable', 'addressables')
            ->using(Addressable::class)
            ->withPivot('kind', 'is_primary', 'priority', 'valid_from', 'valid_to')
            ->withTimestamps();
    }

    /**
     * Get the display name (short name if available, otherwise full name).
     */
    public function displayName(): string
    {
        return $this->short_name ?? $this->full_name;
    }

    /**
     * Scope a query to only include active employees.
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Whether this employee is a Agent.
     */
    public function isAgent(): bool
    {
        return $this->employee_type === 'agent';
    }

    /**
     * Scope a query to only include Agent employees.
     */
    public function scopeAgent($query): void
    {
        $query->where('employee_type', 'agent');
    }

    /**
     * Scope a query to only include human employees (non-Agent).
     */
    public function scopeHuman($query): void
    {
        $query->where('employee_type', '!=', 'agent');
    }
}
