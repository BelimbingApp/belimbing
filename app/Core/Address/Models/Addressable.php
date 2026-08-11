<?php

namespace App\Core\Address\Models;

use App\Core\Address\Exceptions\AddressTenantAssignmentException;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Addressable extends MorphPivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'addressables';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'address_id',
        'addressable_type',
        'addressable_id',
        'kind',
        'is_primary',
        'priority',
        'valid_from',
        'valid_to',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Addressable $addressable): void {
            $addressable->assertSameTenantOwnership();
        });

        static::updating(function (Addressable $addressable): void {
            if ($addressable->isDirty(['address_id', 'addressable_type', 'addressable_id'])) {
                $addressable->assertSameTenantOwnership();
            }
        });
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => 'array',
            'is_primary' => 'boolean',
            'priority' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the owning model (Company, Employee, etc).
     */
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->address_id !== null ? ['name' => 'address', 'id' => (int) $this->address_id] : null;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array<string, mixed>>
     */
    public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
    {
        $entries = [];

        foreach ($this->ownerAuditSubjects($event) as $subject) {
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
     * @return list<array{name: string, id: int}>
     */
    private function ownerAuditSubjects(string $event): array
    {
        $subjects = [];
        $current = $this->ownerAuditSubject($this->addressable_type, $this->addressable_id);

        if ($current !== null) {
            $subjects[$current['name'].'#'.$current['id']] = $current;
        }

        if ($event === 'updated') {
            $original = $this->ownerAuditSubject(
                $this->getOriginal('addressable_type'),
                $this->getOriginal('addressable_id'),
            );

            if ($original !== null) {
                $subjects[$original['name'].'#'.$original['id']] = $original;
            }
        }

        return array_values($subjects);
    }

    /**
     * @return array{name: string, id: int}|null
     */
    private function ownerAuditSubject(mixed $type, mixed $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        $subjectName = match ((string) $type) {
            Company::class, (new Company)->getMorphClass() => 'company',
            Employee::class, (new Employee)->getMorphClass() => 'employee',
            default => null,
        };

        return $subjectName !== null
            ? ['name' => $subjectName, 'id' => (int) $id]
            : null;
    }

    private function assertSameTenantOwnership(): void
    {
        $address = Address::query()->find($this->address_id);
        $ownerTenantId = $this->ownerTenantId();

        if ($address === null || $ownerTenantId === null) {
            throw new AddressTenantAssignmentException(
                'An address attachment requires an existing live address and a supported live owner.',
                [
                    'address_id' => $this->address_id !== null ? (int) $this->address_id : null,
                    'addressable_type' => $this->addressable_type,
                    'addressable_id' => $this->addressable_id !== null ? (int) $this->addressable_id : null,
                ],
            );
        }

        if ((int) $address->tenant_id !== $ownerTenantId) {
            throw new AddressTenantAssignmentException(
                'An address cannot be attached to an owner from another tenant.',
                [
                    'address_id' => (int) $address->id,
                    'address_tenant_id' => (int) $address->tenant_id,
                    'addressable_type' => $this->addressable_type,
                    'addressable_id' => (int) $this->addressable_id,
                    'owner_tenant_id' => $ownerTenantId,
                ],
            );
        }
    }

    private function ownerTenantId(): ?int
    {
        $type = (string) $this->addressable_type;
        $id = (int) $this->addressable_id;

        if (in_array($type, [Company::class, (new Company)->getMorphClass()], true)) {
            $tenantId = Company::query()->whereKey($id)->value('tenant_id');

            return $tenantId !== null ? (int) $tenantId : null;
        }

        if (in_array($type, [Employee::class, (new Employee)->getMorphClass()], true)) {
            $tenantId = Employee::query()
                ->whereKey($id)
                ->whereHas('company')
                ->with('company:id,tenant_id')
                ->first()
                ?->company
                ?->tenant_id;

            return $tenantId !== null ? (int) $tenantId : null;
        }

        return null;
    }
}
