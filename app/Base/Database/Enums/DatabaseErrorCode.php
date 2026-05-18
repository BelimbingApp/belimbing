<?php

namespace App\Base\Database\Enums;

use App\Base\Foundation\Enums\BlbErrorCode;

enum DatabaseErrorCode: string implements BlbErrorCode
{
    case DEV_SEEDER_NON_LOCAL_ENV = 'dev_seeder_non_local_env';
    case CIRCULAR_SEEDER_DEPENDENCY = 'circular_seeder_dependency';
    case DATABASE_QUERY_INVALID = 'database_query_invalid';
    case DATABASE_QUERY_EXECUTION_FAILED = 'database_query_execution_failed';
    case DATABASE_DRIVER_UNSUPPORTED = 'database_driver_unsupported';
    case DATABASE_IDENTIFIER_TOO_LONG = 'database_identifier_too_long';
    case BACKUP_CONFIGURATION_INVALID = 'backup_configuration_invalid';
    case BACKUP_DRIVER_UNSUPPORTED = 'backup_driver_unsupported';
    case BACKUP_TOOLING_MISSING = 'backup_tooling_missing';
    case BACKUP_DUMP_FAILED = 'backup_dump_failed';
    case BACKUP_ENCRYPTION_FAILED = 'backup_encryption_failed';
    case BACKUP_DECRYPTION_FAILED = 'backup_decryption_failed';
    case BACKUP_STORAGE_FAILED = 'backup_storage_failed';
    case BACKUP_ARTIFACT_NOT_FOUND = 'backup_artifact_not_found';
    case BACKUP_ARTIFACT_CORRUPT = 'backup_artifact_corrupt';
    case BACKUP_RESTORE_REFUSED = 'backup_restore_refused';
    case BACKUP_RESTORE_FAILED = 'backup_restore_failed';
}
