<?php

namespace App\Base\Tenancy\Models;

use App\Base\Tenancy\Exceptions\LicenseeTenantDeletionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant is the platform's outer isolation boundary: one party renting
 * the software. Companies remain the inner organizational boundary inside
 * a tenant.
 *
 * Tenants form a hierarchy via parent_id so an operator (hosting partner,
 * reseller) can administer its own customer sub-tenants. Tenant id=1 is
 * the licensee tenant, created at install; a single-tenant instance is the
 * degenerate case and surfaces no tenancy UI.
 *
 * Base cannot depend on Core, so this model deliberately has no companies()
 * relation — the inverse lives on App\Core\Company\Models\Company::tenant().
 */
class Tenant extends Model
{
    /**
     * The licensee tenant is always id=1, created during installation.
     */
    public const LICENSEE_TENANT_ID = 1;

    use SoftDeletes;

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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Tenant $tenant): void {
            if ($tenant->id === self::LICENSEE_TENANT_ID) {
                throw new LicenseeTenantDeletionException;
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

    public function isLicensee(): bool
    {
        return $this->id === self::LICENSEE_TENANT_ID;
    }

    /**
     * Create or update the licensee tenant at id=1. Idempotent.
     *
     * The best available name wins: an explicit name, then the licensee
     * company's name, then the existing row left untouched.
     *
     * @return bool Whether the tenant was created (false if updated/unchanged)
     */
    public static function provisionLicenseeTenant(?string $name = null): bool
    {
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        $existing = static::query()->find(self::LICENSEE_TENANT_ID);

        if ($existing !== null && $name === null) {
            return false;
        }

        $tenant = static::unguarded(fn () => static::query()->updateOrCreate(
            ['id' => self::LICENSEE_TENANT_ID],
            ['name' => $name ?? 'Licensee', 'status' => 'active'],
        ));

        // PostgreSQL sequences don't advance on explicit-ID inserts — reset to
        // avoid unique-constraint violations when subsequent inserts auto-increment.
        $connection = static::resolveConnection();
        if ($tenant->wasRecentlyCreated && $connection->getDriverName() === 'pgsql') {
            $connection->statement(
                "SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT COALESCE(MAX(id), 0) FROM tenants))"
            );
        }

        return $tenant->wasRecentlyCreated;
    }
}
