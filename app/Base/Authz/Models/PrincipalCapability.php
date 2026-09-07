<?php

namespace App\Base\Authz\Models;

use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $principal_type
 * @property int|null $principal_id
 * @property string $capability_key
 * @property bool $is_allowed
 */
class PrincipalCapability extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_authz_principal_capabilities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'principal_type',
        'principal_id',
        'capability_key',
        'is_allowed',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_allowed' => 'boolean',
    ];

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
