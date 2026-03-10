<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Models;

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Address\Models\Addressable;
use App\Modules\Core\Company\Database\Factories\CompanyFactory;
use App\Modules\Core\Company\Exceptions\LicenseeCompanyDeletionException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Company extends Model
{
    /**
     * The licensee company is always id=1, created during installation.
     */
    public const LICENSEE_ID = 1;

    use HasFactory, SoftDeletes;

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
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($company): void {
            if (empty($company->code)) {
                $company->code = Str::lower(Str::slug($company->name, '_'));
            }
        });

        static::deleting(function ($company): void {
            if ($company->id === self::LICENSEE_ID) {
                throw new LicenseeCompanyDeletionException;
            }
        });
    }

    /**
     * Get the parent company.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_id');
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

    /**
     * Check if this is the licensee company (the company operating this Belimbing instance).
     */
    public function isLicensee(): bool
    {
        return $this->id === self::LICENSEE_ID;
    }

    /**
     * Ensure the licensee company (id=1) exists.
     *
     * Idempotent — safe to call from migrations, setup scripts, and UI.
     * Resets the PostgreSQL sequence after explicit-ID insert to avoid
     * auto-increment collisions.
     *
     * @param  string  $name  Display name for the licensee company
     * @return bool Whether the licensee was created (false if already existed).
     */
    public static function provisionLicensee(string $name = 'My Company'): bool
    {
        if (static::query()->where('id', self::LICENSEE_ID)->exists()) {
            return false;
        }

        static::unguarded(fn () => static::query()->create([
            'id' => self::LICENSEE_ID,
            'name' => $name,
            'status' => 'active',
        ]));

        // PostgreSQL sequences don't advance on explicit-ID inserts — reset to
        // avoid unique-constraint violations when subsequent inserts auto-increment.
        $connection = static::resolveConnection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(
                "SELECT setval(pg_get_serial_sequence('companies', 'id'), (SELECT COALESCE(MAX(id), 0) FROM companies))"
            );
        }

        return true;
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
