<?php

namespace App\Modules\Core\Address\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
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
}
