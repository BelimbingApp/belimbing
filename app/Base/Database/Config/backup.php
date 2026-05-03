<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Master Switch
    |--------------------------------------------------------------------------
    |
    | When false, blb:db:backup and blb:db:restore exit immediately with a
    | message. Disable on managed-database deployments (RDS, Cloud SQL, Neon,
    | Supabase, Turso, LiteFS Cloud, ...) where the platform performs its own
    | encrypted snapshots and BLB should not run a parallel backup system.
    |
    */
    'enabled' => env('BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Name of a Laravel filesystem disk (configured in config/filesystems.php)
    | where backup artifacts and manifests are written. Any driver works:
    | local, s3, sftp, etc. The runtime identity used by this disk should be
    | append-oriented (write-only when possible); restore uses a separate
    | identity.
    |
    */
    'disk' => env('BACKUP_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Local Override Disk
    |--------------------------------------------------------------------------
    |
    | Disk used when blb:db:backup is invoked with --local. Intended for
    | development and emergency use. Defaults to Laravel's built-in 'local'
    | disk under storage/app/private.
    |
    */
    'local_disk' => env('BACKUP_LOCAL_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Storage Path Prefix
    |--------------------------------------------------------------------------
    |
    | Path under the chosen disk where artifacts and manifests live.
    | Final layout: {prefix}/{environment}/{timestamp}-{backup_id}.bak[.enc]
    |
    */
    'path_prefix' => env('BACKUP_PATH_PREFIX', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Mode is one of:
    |   - 'none'           : compressed but unencrypted; only acceptable for
    |                        small deployments with no sensitive data.
    |   - 'passphrase'     : default; uses libsodium (Argon2id KDF +
    |                        XChaCha20-Poly1305 secretstream). The passphrase
    |                        is read from the configured env var.
    |   - 'age-recipients' : reserved (not implemented in Phase 1).
    |   - 'kms'            : reserved (not implemented in Phase 1).
    |
    */
    'encryption' => [
        'mode' => env('BACKUP_ENCRYPTION_MODE', 'passphrase'),

        // Name of the env var that holds the passphrase. The passphrase
        // itself is never persisted in this file or in the manifest.
        'passphrase_env' => env('BACKUP_PASSPHRASE_ENV', 'BACKUP_PASSPHRASE'),

        // For mode = 'age-recipients' (Phase 4).
        'recipients' => [],

        // For mode = 'kms' (Phase 4).
        'kms_key' => env('BACKUP_KMS_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | --prune deletes artifacts older than keep_days, but always preserves at
    | least the most recent keep_count regardless of age. Set keep_days to 0
    | to disable age-based pruning. Set keep_count to 0 to disable the floor.
    |
    */
    'retention' => [
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        'keep_count' => (int) env('BACKUP_KEEP_COUNT', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore Guard
    |--------------------------------------------------------------------------
    |
    | When false (default), blb:db:restore refuses to write into the database
    | currently configured for the application. Promote a restored database
    | by reconfiguring the connection, not by flipping this flag.
    |
    */
    'restore' => [
        'allow_current_database' => (bool) env('BACKUP_RESTORE_ALLOW_CURRENT_DB', false),
    ],
];
