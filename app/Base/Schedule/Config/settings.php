<?php

use App\Base\Schedule\Services\ScheduleHistoryPruner;

return [
    'definitions' => [
        ScheduleHistoryPruner::KEEP_DAYS_KEY => [
            'type' => 'integer',
            'scopes' => ['global'],
            'default' => ScheduleHistoryPruner::DEFAULT_KEEP_DAYS,
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'integer', 'min:0', 'max:3650'],
            'label' => 'History retention',
            'help' => 'Keep completed schedule-run history for this many days. Use 0 to disable age-based pruning.',
            'editable' => 'admin.system.schedule',
            'capability' => 'admin.system.schedule.manage',
        ],
    ],
];
