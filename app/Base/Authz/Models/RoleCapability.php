<?php

namespace App\Base\Authz\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $id
 * @property int $role_id
 * @property string $capability_key
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RoleCapability extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_authz_role_capabilities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'capability_key',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** @return array{name: string, id: int}|null */
    public function getAuditSubject(): ?array
    {
        return ['name' => 'role', 'id' => (int) $this->role_id];
    }
}
