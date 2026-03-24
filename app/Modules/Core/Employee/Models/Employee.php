<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Employee\Models;

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Address\Models\Addressable;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\Department;
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

    use HasFactory;

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
     * Whether this employee is Lara, BLB's system orchestrator Agent.
     */
    public function isLara(): bool
    {
        return $this->id === self::LARA_ID;
    }

    /**
     * Whether this employee is Kodi, BLB's system developer Agent.
     */
    public function isKodi(): bool
    {
        return $this->id === self::KODI_ID;
    }

    /**
     * Whether this employee is a system Agent (Lara or Kodi).
     *
     * System agents are provisioned at install time and cannot be deleted.
     */
    public function isSystemAgent(): bool
    {
        return $this->isLara() || $this->isKodi();
    }

    /**
     * Whether Lara is provisioned (Employee record exists) and activated
     * (has a resolvable LLM config — either workspace-level or company default).
     *
     * Returns a tri-state: null = not provisioned, false = provisioned but
     * not activated, true = fully activated.
     */
    public static function laraActivationState(): ?bool
    {
        if (! static::query()->whereKey(self::LARA_ID)->exists()) {
            return null;
        }

        $resolver = app(ConfigResolver::class);

        if ($resolver->resolve(self::LARA_ID) !== []) {
            return true;
        }

        return $resolver->resolveDefault(
            Company::LICENSEE_ID,
        ) !== null;
    }

    /**
     * Ensure Lara (the system Agent) exists.
     *
     * Idempotent — safe to call from migrations, setup scripts, and UI.
     * Requires the Licensee company to exist first. Resets the PostgreSQL
     * sequence after explicit-ID insert to avoid auto-increment collisions.
     *
     * @return bool Whether Lara was created (false if already existed or Licensee missing).
     */
    public static function provisionLara(): bool
    {
        if (static::query()->where('id', self::LARA_ID)->exists()) {
            return false;
        }

        if (! Company::query()->where('id', Company::LICENSEE_ID)->exists()) {
            return false;
        }

        static::unguarded(fn () => static::query()->create([
            'id' => self::LARA_ID,
            'company_id' => Company::LICENSEE_ID,
            'employee_type' => 'agent',
            'employee_number' => 'SYS-001',
            'full_name' => 'Lara Belimbing',
            'short_name' => 'Lara',
            'designation' => 'System Assistant',
            'job_description' => 'BLB\'s system Agent. Guides users through setup and onboarding, explains framework architecture and conventions, orchestrates tasks by delegating to specialised Agents, and bootstraps the AI workforce on fresh installs.',
            'status' => 'active',
            'employment_start' => now()->toDateString(),
        ]));

        // PostgreSQL sequences don't advance on explicit-ID inserts — reset to
        // avoid unique-constraint violations when subsequent inserts auto-increment.
        $connection = static::resolveConnection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                "SELECT setval(pg_get_serial_sequence('employees', 'id'), (SELECT COALESCE(MAX(id), 0) FROM employees))"
            );
        }

        return true;
    }

    /**
     * Ensure Kodi (the system developer Agent) exists.
     *
     * Idempotent — safe to call from migrations, setup scripts, and UI.
     * Requires the Licensee company to exist first. Resets the PostgreSQL
     * sequence after explicit-ID insert to avoid auto-increment collisions.
     *
     * @return bool Whether Kodi was created (false if already existed or Licensee missing).
     */
    public static function provisionKodi(): bool
    {
        if (static::query()->where('id', self::KODI_ID)->exists()) {
            return false;
        }

        if (! Company::query()->where('id', Company::LICENSEE_ID)->exists()) {
            return false;
        }

        static::unguarded(fn () => static::query()->create([
            'id' => self::KODI_ID,
            'company_id' => Company::LICENSEE_ID,
            'supervisor_id' => self::LARA_ID,
            'employee_type' => 'agent',
            'employee_number' => 'SYS-002',
            'full_name' => 'Kodi Belimbing',
            'short_name' => 'Kodi',
            'designation' => 'System Developer',
            'job_description' => 'BLB\'s system developer Agent. Builds modules, writes migrations, models, tests, and Livewire components following framework conventions. Works through IT tickets assigned by supervisors.',
            'status' => 'active',
            'employment_start' => now()->toDateString(),
        ]));

        $connection = static::resolveConnection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                "SELECT setval(pg_get_serial_sequence('employees', 'id'), (SELECT COALESCE(MAX(id), 0) FROM employees))"
            );
        }

        return true;
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
