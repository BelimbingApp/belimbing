<?php

namespace App\Base\FeatureFlags\Models;

use Illuminate\Database\Eloquent\Model;

/**
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
}
