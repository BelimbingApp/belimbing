<?php

namespace App\Base\Authz\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleCapability extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_authz_role_capabilities';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'role_id',
        'capability_key',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** @return array{name: string, id: int}|null */
    public function getAuditSubject(): ?array
    {
        return $this->role_id !== null ? ['name' => 'role', 'id' => (int) $this->role_id] : null;
    }
}
