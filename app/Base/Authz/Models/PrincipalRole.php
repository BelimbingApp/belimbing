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
}
