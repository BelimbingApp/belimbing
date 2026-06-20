<?php

namespace App\Base\Authz\Models;

use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrincipalRole extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_authz_principal_roles';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'principal_type',
        'principal_id',
        'role_id',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        if ($this->principal_type !== PrincipalType::USER->value || $this->principal_id === null) {
            return null;
        }

        return ['name' => 'user', 'id' => (int) $this->principal_id];
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array<string, mixed>>
     */
    public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
    {
        $roleIds = [$this->role_id];

        if ($event === 'updated') {
            $roleIds[] = $this->getOriginal('role_id');
        }

        return collect($roleIds)
            ->filter(fn (mixed $roleId): bool => $roleId !== null)
            ->map(fn (mixed $roleId): int => (int) $roleId)
            ->unique()
            ->values()
            ->map(fn (int $roleId): array => [
                'subject_name' => 'role',
                'subject_id' => $roleId,
                'event' => $event,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ])
            ->all();
    }
}
