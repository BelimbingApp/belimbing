<?php

namespace App\Base\Tenancy\Models;

use App\Base\Tenancy\Exceptions\PlatformOperatorTenantDeletionException;
use App\Base\Tenancy\Exceptions\PlatformOperatorTenantInvariantViolationException;
use App\Base\Tenancy\Exceptions\PlatformOperatorTenantNotProvisionedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A tenant is the platform's outer data-isolation and subscription boundary.
 * Companies remain the inner organizational boundary inside a tenant.
 *
 * Tenants form a hierarchy via parent_id so a hosting partner or reseller can
 * administer customer sub-tenants. Exactly one live tenant is explicitly
 * marked as the platform operator; numeric IDs carry no role semantics.
 *
 * Base cannot depend on Core, so this model deliberately has no companies()
 * relation — the inverse lives on App\Core\Company\Models\Company::tenant().
 */
class Tenant extends Model
{
    use SoftDeletes;

    /**
     * @deprecated Historical migration replay only. Runtime code must resolve
     * the explicit platform-operator marker instead. Remove with the legacy
     * pre-explicit-tenancy migration baseline.
     */
    public const LICENSEE_TENANT_ID = 1;

    protected $table = 'tenants';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_platform_operator' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Tenant $tenant): void {
            if ($tenant->isPlatformOperator()) {
                throw new PlatformOperatorTenantDeletionException((int) $tenant->id);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Tenant::class, 'parent_id');
    }

    public function isPlatformOperator(): bool
    {
        return $this->is_platform_operator === true;
    }

    /**
     * Resolve the live platform-operator tenant, or null before provisioning.
     * A soft-deleted marked tenant is corruption rather than absence.
     */
    public static function platformOperator(): ?self
    {
        $marked = static::withTrashed()
            ->where('is_platform_operator', true)
            ->orderBy('id')
            ->get();

        if ($marked->count() > 1) {
            throw new PlatformOperatorTenantInvariantViolationException(
                'Multiple tenants are marked as the platform operator.',
                ['tenant_ids' => $marked->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()],
            );
        }

        $operator = $marked->first();

        if ($operator?->trashed()) {
            throw new PlatformOperatorTenantInvariantViolationException(
                'The platform-operator tenant is soft-deleted.',
                ['tenant_id' => (int) $operator->id],
            );
        }

        return $operator;
    }

    public static function requirePlatformOperator(): self
    {
        return static::platformOperator()
            ?? throw new PlatformOperatorTenantNotProvisionedException;
    }

    /**
     * Create or update the explicitly marked platform-operator tenant.
     *
     * Uses an ignored unique-index conflict so concurrent first provisioners
     * can resolve the same auto-generated winner without relying on an ID.
     */
    public static function provisionPlatformOperator(?string $name = null): bool
    {
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;
        $existing = static::platformOperator();

        if ($existing !== null) {
            if ($name !== null) {
                $existing->forceFill(['name' => $name, 'status' => 'active'])->save();
            }

            return false;
        }

        $inserted = DB::table('tenants')->insertOrIgnore([
            'parent_id' => null,
            'name' => $name ?? 'Platform operator',
            'status' => 'active',
            'is_platform_operator' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $operator = static::platformOperator();

        if ($operator === null) {
            throw new PlatformOperatorTenantInvariantViolationException(
                'Platform-operator tenant provisioning did not produce an operator tenant.'
            );
        }

        return $inserted === 1;
    }
}
