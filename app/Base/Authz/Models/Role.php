<?php

namespace App\Base\Authz\Models;

use App\Core\Company\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $id
 * @property int|null $company_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property bool $is_system
 * @property bool $grant_all
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Role extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_authz_roles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'is_system',
        'grant_all',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
        'grant_all' => 'boolean',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PrincipalRole, $this>
     */
    public function principalRoles(): HasMany
    {
        return $this->hasMany(PrincipalRole::class, 'role_id');
    }

    /**
     * @return HasMany<RoleCapability, $this>
     */
    public function capabilities(): HasMany
    {
        return $this->hasMany(RoleCapability::class, 'role_id');
    }

    public function principalCount(): int
    {
        return $this->principalRoles()->count();
    }

    /** @return array{name: string, id: int}|null */
    public function getAuditSubject(): ?array
    {
        if ($this->id === null) {
            return null;
        }

        return ['name' => 'role', 'id' => (int) $this->id];
    }
}
