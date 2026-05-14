<?php

namespace App\Base\Database\Concerns;

use App\Modules\Core\Company\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    protected const COMPANY_FILLABLE = ['company_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
