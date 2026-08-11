<?php

namespace App\Core\Address\Models;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Address\Database\Factories\AddressFactory;
use App\Core\Address\Exceptions\AddressTenantAssignmentException;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\Geonames\Models\Admin1;
use App\Core\Geonames\Models\Country;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'addresses';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'label',
        'phone',
        'line1',
        'line2',
        'line3',
        'locality',
        'postcode',
        'country_iso',
        'admin1Code',
        'rawInput',
        'source',
        'sourceRef',
        'parserVersion',
        'parseConfidence',
        'parsed_at',
        'normalized_at',
        'normalization_notes',
        'verificationStatus',
        'metadata',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AddressFactory
    {
        return new AddressFactory;
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'parseConfidence' => 'decimal:4',
            'parsed_at' => 'datetime',
            'normalized_at' => 'datetime',
            'normalization_notes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)
            ->forTenant(app(TenantContext::class)->requireTenantId());
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Address $address): void {
            if ($address->tenant_id === null) {
                $address->tenant_id = app(TenantContext::class)->requireTenantId();
            }

            if (! Tenant::query()->whereKey($address->tenant_id)->exists()) {
                throw new AddressTenantAssignmentException(
                    'An address must belong to an existing, non-deleted tenant.',
                    ['tenant_id' => (int) $address->tenant_id],
                );
            }
        });

        static::updating(function (Address $address): void {
            if ($address->isDirty('tenant_id')) {
                throw new AddressTenantAssignmentException(
                    'An address tenant assignment is immutable.',
                    [
                        'address_id' => (int) $address->id,
                        'tenant_id' => (int) $address->tenant_id,
                        'original_tenant_id' => (int) $address->getOriginal('tenant_id'),
                    ],
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): void
    {
        $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Get the Geonames country referenced by this address.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_iso', 'iso');
    }

    /**
     * Validation rules for address fields (shared by create form and inline-edit).
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'line1' => ['nullable', 'string'],
            'line2' => ['nullable', 'string'],
            'line3' => ['nullable', 'string'],
            'locality' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
            'sourceRef' => ['nullable', 'string', 'max:255'],
            'rawInput' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->id !== null ? ['name' => 'address', 'id' => (int) $this->id] : null;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array<string, mixed>>
     */
    public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
    {
        if ($this->id === null) {
            return [];
        }

        $entries = [];

        foreach (Addressable::query()->where('address_id', $this->id)->get() as $addressable) {
            $subject = $this->addressableAuditSubject($addressable);

            if ($subject === null) {
                continue;
            }

            $entries[] = [
                'subject_name' => $subject['name'],
                'subject_id' => $subject['id'],
                'event' => $event,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ];
        }

        return $entries;
    }

    /**
     * Get the Geonames admin1 referenced by this address.
     */
    public function admin1(): BelongsTo
    {
        return $this->belongsTo(Admin1::class, 'admin1Code', 'code');
    }

    /**
     * @return array{name: string, id: int}|null
     */
    private function addressableAuditSubject(Addressable $addressable): ?array
    {
        if ($addressable->addressable_id === null) {
            return null;
        }

        $type = (string) $addressable->addressable_type;
        $subjectName = match ($type) {
            Company::class, (new Company)->getMorphClass() => 'company',
            Employee::class, (new Employee)->getMorphClass() => 'employee',
            default => null,
        };

        return $subjectName !== null
            ? ['name' => $subjectName, 'id' => (int) $addressable->addressable_id]
            : null;
    }
}
