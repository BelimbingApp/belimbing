<?php

namespace App\Base\Database\Exceptions;

use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Foundation\Exceptions\BlbException;
use Throwable;

/**
 * Thrown when a database backup or restore operation fails at a defined boundary.
 */
final class BackupException extends BlbException
{
    public static function configurationInvalid(string $reason): self
    {
        return new self(
            'Backup configuration invalid: '.$reason,
            DatabaseErrorCode::BACKUP_CONFIGURATION_INVALID,
            ['reason' => $reason],
        );
    }

    public static function driverUnsupported(string $driver): self
    {
        return new self(
            "Backup driver '{$driver}' is not supported.",
            DatabaseErrorCode::BACKUP_DRIVER_UNSUPPORTED,
            ['driver' => $driver],
        );
    }

    public static function toolingMissing(string $tool, string $hint = ''): self
    {
        $message = "Required tool not available: {$tool}";

        if ($hint !== '') {
            $message .= " ({$hint})";
        }

        return new self(
            $message,
            DatabaseErrorCode::BACKUP_TOOLING_MISSING,
            ['tool' => $tool, 'hint' => $hint],
        );
    }

    public static function dumpFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'Database dump failed: '.$detail,
            DatabaseErrorCode::BACKUP_DUMP_FAILED,
            ['detail' => $detail],
            previous: $previous,
        );
    }

    public static function encryptionFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'Backup encryption failed: '.$detail,
            DatabaseErrorCode::BACKUP_ENCRYPTION_FAILED,
            ['detail' => $detail],
            previous: $previous,
        );
    }

    public static function decryptionFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'Backup decryption failed: '.$detail,
            DatabaseErrorCode::BACKUP_DECRYPTION_FAILED,
            ['detail' => $detail],
            previous: $previous,
        );
    }

    public static function storageFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'Backup storage write failed: '.$detail,
            DatabaseErrorCode::BACKUP_STORAGE_FAILED,
            ['detail' => $detail],
            previous: $previous,
        );
    }

    public static function artifactNotFound(string $backupId): self
    {
        return new self(
            "Backup artifact not found: {$backupId}",
            DatabaseErrorCode::BACKUP_ARTIFACT_NOT_FOUND,
            ['backup_id' => $backupId],
        );
    }

    public static function artifactCorrupt(string $detail): self
    {
        return new self(
            'Backup artifact failed integrity check: '.$detail,
            DatabaseErrorCode::BACKUP_ARTIFACT_CORRUPT,
            ['detail' => $detail],
        );
    }

    public static function restoreRefused(string $reason): self
    {
        return new self(
            'Restore refused: '.$reason,
            DatabaseErrorCode::BACKUP_RESTORE_REFUSED,
            ['reason' => $reason],
        );
    }

    public static function restoreFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            'Restore failed: '.$detail,
            DatabaseErrorCode::BACKUP_RESTORE_FAILED,
            ['detail' => $detail],
            previous: $previous,
        );
    }
}
