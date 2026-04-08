<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Models;

use App\Base\Foundation\Contracts\CompanyScoped;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\ExternalAccess;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements CompanyScoped
{
    /** @use HasFactory<\App\Modules\Core\User\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserFactory
    {
        return new UserFactory;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['company_id', 'employee_id', 'name', 'email', 'password'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the company ID the user belongs to.
     *
     * Prefer the direct user scope and fall back to the linked employee's
     * company when the user record does not persist company_id.
     */
    public function getCompanyId(): ?int
    {
        if ($this->company_id !== null) {
            return (int) $this->company_id;
        }

        return $this->employee?->company_id !== null ? (int) $this->employee->company_id : null;
    }

    /**
     * Get the company this user belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get external accesses granted to this user.
     */
    public function externalAccesses(): HasMany
    {
        return $this->hasMany(ExternalAccess::class, 'user_id');
    }

    /**
     * Get valid external accesses for this user.
     */
    public function validExternalAccesses(): HasMany
    {
        return $this->externalAccesses()->valid();
    }

    /**
     * Get the employee linked to this user.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get this user's pinned items, ordered by sort_order.
     */
    public function pins(): HasMany
    {
        return $this->hasMany(UserPin::class, 'user_id')
            ->orderBy('sort_order');
    }

    /**
     * Get the ordered list of pinned items as arrays for the sidebar.
     *
     * @return list<array{id: int, label: string, url: string, icon: string|null}>
     */
    public function getPins(): array
    {
        return $this->pins()
            ->get(['id', 'label', 'url', 'icon'])
            ->map(fn (UserPin $pin) => [
                'id' => $pin->id,
                'label' => $pin->label,
                'url' => $pin->url,
                'icon' => $pin->icon,
            ])
            ->all();
    }

    /**
     * Get active Agents directly supervised by this user's employee.
     *
     * @return EloquentCollection<int, Employee>
     */
    public function getAgents(): EloquentCollection
    {
        $supervisorId = $this->employee?->id;

        if (! is_int($supervisorId)) {
            return new EloquentCollection;
        }

        return Employee::query()
            ->agent()
            ->where('id', '!=', Employee::LARA_ID)
            ->where('supervisor_id', $supervisorId)
            ->active()
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Check whether this user can access a supervised Agent.
     *
     * Lara is excluded; Lara access uses a dedicated system path/policy.
     */
    public function canAccessSupervisedAgent(int $employeeId): bool
    {
        if ($employeeId === Employee::LARA_ID) {
            return false;
        }

        $supervisorId = $this->employee?->id;

        if (! is_int($supervisorId)) {
            return false;
        }

        return Employee::query()
            ->agent()
            ->whereKey($employeeId)
            ->where('supervisor_id', $supervisorId)
            ->exists();
    }
}
