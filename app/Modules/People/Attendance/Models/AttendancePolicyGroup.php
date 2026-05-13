<?php

namespace App\Modules\People\Attendance\Models;

use App\Base\Database\Concerns\BelongsToCompany;
use App\Base\Database\Concerns\HasActiveInactiveStatus;
use App\Base\Database\Concerns\HasEffectiveDateRange;
use App\Base\Database\Concerns\TracksExternalSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePolicyGroup extends Model
{
    use BelongsToCompany;
    use HasActiveInactiveStatus;
    use HasEffectiveDateRange;
    use TracksExternalSource;

    protected $table = 'people_attendance_policy_groups';

    protected $fillable = [
        ...self::COMPANY_FILLABLE,
        'code',
        'name',
        'cohort_predicate',
        'work_hour_rules',
        'lateness_rules',
        'overtime_rules',
        'overtime_export_rules',
        'lateness_export_rules',
        'payroll_defaults',
        ...self::EFFECTIVE_DATE_RANGE_FILLABLE,
        'version',
        'status',
        ...self::EXTERNAL_SOURCE_FILLABLE,
    ];

    protected function casts(): array
    {
        return [
            'cohort_predicate' => 'array',
            'work_hour_rules' => 'array',
            'lateness_rules' => 'array',
            'overtime_rules' => 'array',
            'overtime_export_rules' => 'array',
            'lateness_export_rules' => 'array',
            'payroll_defaults' => 'array',
            ...self::EFFECTIVE_DATE_RANGE_CASTS,
            'version' => 'integer',
            ...self::EXTERNAL_SOURCE_CASTS,
        ];
    }

    public function allowanceRules(): HasMany
    {
        return $this->hasMany(AttendanceAllowanceRule::class, 'attendance_policy_group_id');
    }
}
