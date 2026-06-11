<?php

return [
    // Backup retention is edited on the Database Backups page, which writes
    // these keys through SettingsService directly rather than declaring
    // editable fields here.
    'runtime' => [
        'backup.retention.*',
    ],
];
