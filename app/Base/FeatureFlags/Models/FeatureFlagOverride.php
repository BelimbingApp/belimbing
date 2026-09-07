<?php

namespace App\Base\FeatureFlags\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $flag
 * @property int $tenant_id
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * Per-tenant override of a declared feature flag.
 *
 * Defaults live in module descriptors; this row exists only when a tenant
 * diverges from the declared default.
 */
class FeatureFlagOverride extends Model
{
    protected $table = 'base_feature_flag_overrides';

    protected $fillable = [
        'tenant_id',
        'flag',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Stable audit identity so operator history is queried as
     * `feature-flag / <flag>` regardless of row id churn.
     *
     * @return array{name: string, id: string}
     */
    public function getAuditSubject(): array
    {
        return ['name' => 'feature-flag', 'id' => (string) $this->flag];
    }
}
