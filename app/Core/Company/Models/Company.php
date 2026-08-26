<?php

namespace App\Core\Company\Models;

use App\Base\Support\Str as BlbStr;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Address\Models\Address;
use App\Core\Address\Models\Addressable;
use App\Core\Company\Database\Factories\CompanyFactory;
use App\Core\Company\Exceptions\CompanyTenantAssignmentException;
use App\Core\Company\Exceptions\PrimaryCompanyDeletionException;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Company extends Model
{
    use HasFactory;
    use SoftDeletes {
        forceDelete as private forceDeleteWithoutPrimaryCompanyGuard;
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id',
        'tenant_id',
        'name',
        'code',
        'status',
        'legal_name',
        'registration_number',
        'tax_id',
        'legal_entity_type_id',
        'jurisdiction',
        'email',
        'website',
        'scope_activities',
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
            'scope_activities' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CompanyFactory
    {
        return new CompanyFactory;
    }

    /**
     * Resolve web route bindings inside the current tenant boundary.
     *
     * Cross-tenant company IDs intentionally resolve as not found so route
     * model binding cannot expose whether another tenant owns that record.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $tenantId = app(TenantContext::class)->currentTenantId();

        if ($tenantId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->forTenant($tenantId);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Company $company): void {
            if ($company->tenant_id === null) {
                $company->tenant_id = app(TenantContext::class)->requireTenantId();
            }

            $tenantId = (int) $company->tenant_id;

            if (! Tenant::query()->whereKey($tenantId)->exists()) {
                throw CompanyTenantAssignmentException::tenantDoesNotExist($tenantId);
            }

            static::assertParentBelongsToTenant($company->parent_id, $tenantId);

            if (empty($company->code)) {
                $company->code = BlbStr::code($company->name);
            }
        });

        static::updating(function (Company $company): void {
            if ($company->isDirty('tenant_id')) {
                throw CompanyTenantAssignmentException::immutable(
                    (int) $company->id,
                    (int) $company->getOriginal('tenant_id'),
                    (int) $company->tenant_id,
                );
            }

            if ($company->isDirty('parent_id')) {
                static::assertParentBelongsToTenant($company->parent_id, (int) $company->tenant_id);
            }
        });
    }

    public function delete(): ?bool
    {
        if ($this->isForceDeleting()) {
            return parent::delete();
        }

        return $this->deleteWithPrimaryCompanyGuard(false);
    }

    public function forceDelete(): ?bool
    {
        return $this->deleteWithPrimaryCompanyGuard(true);
    }

    private function deleteWithPrimaryCompanyGuard(bool $force): ?bool
    {
        if (! $this->exists) {
            return null;
        }

        $tenantId = (int) $this->tenant_id;
        $companyId = (int) $this->id;

        return DB::transaction(function () use ($tenantId, $companyId, $force): ?bool {
            Tenant::withTrashed()->whereKey($tenantId)->lockForUpdate()->first();
            $assignment = TenantPrimaryCompany::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->first();
            $company = static::withTrashed()->whereKey($companyId)->lockForUpdate()->first();

            if ($company === null) {
                return null;
            }

            if ((int) $assignment?->company_id === $companyId) {
                throw new PrimaryCompanyDeletionException($tenantId, $companyId);
            }

            return $force
                ? $this->forceDeleteWithoutPrimaryCompanyGuard()
                : parent::delete();
        });
    }

    private static function assertParentBelongsToTenant(mixed $parentId, int $tenantId): void
    {
        if ($parentId === null) {
            return;
        }

        $parentMatches = static::query()
            ->whereKey($parentId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $parentMatches) {
            throw CompanyTenantAssignmentException::parentTenantMismatch($tenantId, (int) $parentId);
        }
    }

    /**
     * Get the parent company.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_id');
    }

    /**
     * Get the tenant this company belongs to.
     *
     * A company's tenant assignment is set at creation and treated as
     * immutable; moving a company across tenants is a data-migration
     * decision, not an edit.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function primaryCompanyAssignment(): HasOne
    {
        return $this->hasOne(TenantPrimaryCompany::class, 'company_id');
    }

    /**
     * Get the legal entity type.
     */
    public function legalEntityType(): BelongsTo
    {
        return $this->belongsTo(LegalEntityType::class);
    }

    /**
     * Get the child companies (subsidiaries).
     */
    public function children(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_id');
    }

    /**
     * Get all descendants recursively.
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get the departments belonging to the company.
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'company_id');
    }

    /**
     * Get all ancestors up to the root.
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();
        $parent = $this->parent;

        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    /**
     * Check if this company is a root company (no parent).
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Check if this company has children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Get the root company of the hierarchy.
     */
    public function getRootCompany(): ?Company
    {
        if ($this->isRoot()) {
            return $this;
        }

        return $this->ancestors()->last();
    }

    /**
     * Get all company relationships where this company is the primary.
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(CompanyRelationship::class, 'company_id');
    }

    /**
     * Get all company relationships where this company is the related entity.
     */
    public function inverseRelationships(): HasMany
    {
        return $this->hasMany(CompanyRelationship::class, 'related_company_id');
    }

    /**
     * Get all relationships of a specific type.
     *
     * @param  string  $typeCode  The relationship type code
     */
    public function relationshipsOfType(string $typeCode): HasMany
    {
        return $this->relationships()->whereHas('type', function ($query) use ($typeCode): void {
            $query->where('code', $typeCode);
        });
    }

    /**
     * Get all active relationships.
     */
    public function activeRelationships(): HasMany
    {
        return $this->relationships()
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            });
    }

    /**
     * Get external accesses granted by this company.
     */
    public function externalAccesses(): HasMany
    {
        return $this->hasMany(ExternalAccess::class, 'company_id');
    }

    /**
     * Get addresses linked via Address module (addressables pivot).
     */
    public function addresses(): MorphToMany
    {
        return $this->morphToMany(Address::class, 'addressable', 'addressables')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('companies as address_owner_companies')
                    ->whereColumn('address_owner_companies.id', 'addressables.addressable_id')
                    ->whereColumn('address_owner_companies.tenant_id', 'addresses.tenant_id');
            })
            ->using(Addressable::class)
            ->withPivot('kind', 'is_primary', 'priority', 'valid_from', 'valid_to')
            ->withTimestamps();
    }

    /**
     * Get the primary address, or the first address if none is primary.
     */
    public function primaryAddress(): ?Address
    {
        $primary = $this->addresses()->wherePivot('is_primary', true)->first();

        return $primary ?? $this->addresses()->orderByPivot('priority')->first();
    }

    /**
     * Get phone from primary address (phone is tied to address).
     */
    public function phone(): ?string
    {
        return $this->primaryAddress()?->phone;
    }

    /**
     * Check if the company is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the company is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Check if the company is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the company is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function isPrimaryCompany(): bool
    {
        return app(PrimaryCompanyManager::class)->isPrimary($this);
    }

    /**
     * Resolve the canonical admin user id stored in company metadata.
     */
    public function adminUserId(): ?int
    {
        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $adminUserId = $metadata['admin_user_id'] ?? null;

        return is_numeric($adminUserId) ? (int) $adminUserId : null;
    }

    /**
     * Resolve the canonical admin user for this company.
     */
    public function resolveAdminUser(): ?User
    {
        $adminUserId = $this->adminUserId();

        if ($adminUserId === null) {
            return null;
        }

        return User::query()
            ->whereKey($adminUserId)
            ->where('company_id', $this->id)
            ->first();
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->id !== null ? ['name' => 'company', 'id' => (int) $this->id] : null;
    }

    /**
     * Persist the canonical admin user id into company metadata.
     */
    public function assignAdminUser(User $user): void
    {
        if ((int) $user->company_id !== (int) $this->id) {
            return;
        }

        $metadata = $this->metadata;

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['admin_user_id'] = $user->getKey();

        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Activate the company.
     */
    public function activate(): bool
    {
        $this->status = 'active';

        return $this->save();
    }

    /**
     * Suspend the company.
     */
    public function suspend(): bool
    {
        $this->status = 'suspended';

        return $this->save();
    }

    /**
     * Archive the company.
     */
    public function archive(): bool
    {
        $this->status = 'archived';

        return $this->save();
    }

    /**
     * Get the full address as a formatted string (from primary address via Address module).
     */
    public function fullAddress(): ?string
    {
        $address = $this->primaryAddress();
        if (! $address) {
            return null;
        }

        $parts = array_filter([
            $address->line1,
            $address->line2,
            $address->line3,
            $address->locality,
            $address->postcode,
            $address->country_iso,
        ]);

        return ! empty($parts) ? implode(', ', $parts) : null;
    }

    /**
     * Get the display name (legal name if available, otherwise name).
     */
    public function displayName(): string
    {
        return $this->legal_name ?? $this->name;
    }

    /**
     * Scope a query to only include active companies.
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }

    public function scopeForTenant($query, int $tenantId): void
    {
        $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Scope a query to only include root companies (no parent).
     */
    public function scopeRoot($query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Scope a query to only include subsidiaries (has parent).
     */
    public function scopeSubsidiaries($query): void
    {
        $query->whereNotNull('parent_id');
    }
}
