<?php

namespace App\Modules\Core\Company\Models;

use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExternalAccess extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'company_external_accesses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'relationship_id',
        'user_id',
        'permissions',
        'is_active',
        'access_granted_at',
        'access_expires_at',
        'metadata',
    ];

    /**
     * @var list<string>
     */
    protected array $auditRedact = ['metadata'];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
            'access_granted_at' => 'datetime',
            'access_expires_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the company that granted this access.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the relationship this access is based on.
     */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(CompanyRelationship::class, 'relationship_id');
    }

    /**
     * Get the user who has this external access.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->user_id !== null ? ['name' => 'user', 'id' => (int) $this->user_id] : null;
    }

    /**
     * Check if the access is currently valid.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        // Check if access has been granted
        if (
            ! is_null($this->access_granted_at) &&
            $this->access_granted_at->gt($now)
        ) {
            return false;
        }

        // Check if access has expired
        if (
            ! is_null($this->access_expires_at) &&
            $this->access_expires_at->lt($now)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Check if the access has expired.
     */
    public function hasExpired(): bool
    {
        return ! is_null($this->access_expires_at) &&
            $this->access_expires_at->lt(now());
    }

    /**
     * Check if the access is pending (not yet granted).
     */
    public function isPending(): bool
    {
        return ! is_null($this->access_granted_at) &&
            $this->access_granted_at->gt(now());
    }

    /**
     * Grant access immediately.
     */
    public function grant(): bool
    {
        $this->access_granted_at = now();
        $this->is_active = true;

        return $this->save();
    }

    /**
     * Revoke access.
     */
    public function revoke(): bool
    {
        $this->is_active = false;

        return $this->save();
    }

    /**
     * Extend access expiration.
     */
    public function extendTo(string $date): bool
    {
        $this->access_expires_at = $date;

        return $this->save();
    }

    /**
     * Make access indefinite (no expiration).
     */
    public function makeIndefinite(): bool
    {
        $this->access_expires_at = null;

        return $this->save();
    }

    /**
     * Check if user has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (is_null($this->permissions)) {
            return false;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Grant a permission.
     */
    public function grantPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        if (! in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->permissions = $permissions;

            return $this->save();
        }

        return true;
    }

    /**
     * Revoke a permission.
     */
    public function revokePermission(string $permission): bool
    {
        if (is_null($this->permissions)) {
            return true;
        }

        $permissions = array_filter(
            $this->permissions,
            fn ($p) => $p !== $permission,
        );
        $this->permissions = array_values($permissions);

        return $this->save();
    }

    /**
     * Scope a query to only include valid accesses.
     */
    public function scopeValid($query): void
    {
        $query
            ->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNull('access_granted_at')->orWhere(
                    'access_granted_at',
                    '<=',
                    now()
                );
            })
            ->where(function ($q): void {
                $q->whereNull('access_expires_at')->orWhere(
                    'access_expires_at',
                    '>=',
                    now()
                );
            });
    }

    /**
     * Scope a query to only include active accesses.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to only include expired accesses.
     */
    public function scopeExpired($query): void
    {
        $query
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<', now());
    }

    /**
     * Scope a query to only include pending accesses.
     */
    public function scopePending($query): void
    {
        $query
            ->whereNotNull('access_granted_at')
            ->where('access_granted_at', '>', now());
    }
}
