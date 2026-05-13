<?php

namespace App\Base\Database\Concerns;

trait HasCompanyScopedExternalLifecycle
{
    use BelongsToCompany;
    use HasActiveInactiveStatus;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected const COMPANY_SCOPED_EXTERNAL_LIFECYCLE_FILLABLE = [
        ...self::COMPANY_FILLABLE,
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    protected const COMPANY_SCOPED_EXTERNAL_LIFECYCLE_CASTS = [
        ...self::EFFECTIVE_DATE_RANGE_CASTS,
        ...self::EXTERNAL_SOURCE_CASTS,
    ];
}
