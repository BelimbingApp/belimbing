<?php

namespace App\Core\Company\Models;

use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPrimaryCompany extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'tenant_primary_companies';

    protected $primaryKey = 'tenant_id';

    /** @var array<int, string> */
    protected $fillable = ['tenant_id', 'company_id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
