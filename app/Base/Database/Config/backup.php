<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    /*
    |--------------------------------------------------------------------------
    | Backup Master Switch
    |--------------------------------------------------------------------------
    |
    | When false, blb:db:backup exits immediately with a message. Disable on
    | managed-database deployments (RDS, Cloud SQL, Neon, Supabase, Turso,
    | LiteFS Cloud, ...) where the platform performs its own encrypted
    | snapshots and BLB should not run a parallel backup system.
    |
    */
    'enabled' => env('BACKUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Source Database Connection
    |--------------------------------------------------------------------------
    |
    | Optional. Name of the Laravel database connection to back up. When null,
    | the default connection is used. Set this when you want backups to target
    | a connection different from the application's primary one.
    |
    */
    'connection' => env('BACKUP_CONNECTION'),

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
    |   - 'app-key'        : default; envelope encryption keyed from APP_KEY
    |                        using HKDF-SHA-256 + XChaCha20-Poly1305. The DEK
    |                        is wrapped under a KEK derived from APP_KEY; no
    |                        separate passphrase required.
    |   - Other values      : register an EncryptionMode factory on
    |                        EncryptionModeRegistry from an extension service
    |                        provider (e.g. age recipients, cloud KMS).
    |
    */
    'encryption' => [
        'mode' => env('BACKUP_ENCRYPTION_MODE', 'app-key'),

        // Example keys for extension-registered modes (consumed only if a mode uses them).
        'recipients' => [],

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
];
