<?php

use App\Base\DateTime\Enums\TimezoneMode;
use App\Base\DateTime\Services\TimezoneSettings;

return [
    'definitions' => [
        TimezoneSettings::LOCALIZATION_TIMEZONE_KEY => [
            'type' => 'string',
            'scopes' => ['company'],
            'default' => 'UTC',
            'nullable' => false,
            'encrypted' => false,
            'rules' => ['required', 'string', 'timezone:all'],
            'label' => 'Company timezone',
            'help' => 'IANA timezone used for Company-mode date and time display.',
            'editable' => 'admin.system.localization',
            'capability' => 'admin.system.localization.manage',
        ],
        TimezoneSettings::MODE_KEY => [
            'type' => 'string',
            'scopes' => ['user'],
            'default' => TimezoneMode::COMPANY->value,
            'nullable' => false,
            'encrypted' => false,
            'rules' => [
                'required',
                'string',
                'in:'.implode(',', array_column(TimezoneMode::cases(), 'value')),
            ],
            'label' => 'Timezone display mode',
            'help' => 'Choose the company timezone, browser-local time, or stored UTC values.',
            'editable' => 'profile.appearance',
            'capability' => 'base.settings.user.manage',
        ],
    ],
];
