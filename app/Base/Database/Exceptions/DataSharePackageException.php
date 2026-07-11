<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DataSharePackageException extends RuntimeException
{
    public static function invalidPackage(string $reason): self
    {
        return new self(__('The Data Share package is invalid: :reason', ['reason' => $reason]));
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(__('Unsupported Data Share package format: :format.', ['format' => $format]));
    }

    public static function expired(string $packageId): self
    {
        return new self(__('Data Share package :package has expired.', ['package' => $packageId]));
    }

    public static function payloadHashMismatch(string $table): self
    {
        return new self(__('Data Share payload :table does not match its manifest hash.', ['table' => $table]));
    }

    public static function schemaMismatch(string $table): self
    {
        return new self(__('Destination schema is not compatible with Data Share table :table.', ['table' => $table]));
    }

    public static function recordLineTooLarge(int $max): self
    {
        return new self(__('A Data Share record exceeds the :max byte line limit.', ['max' => $max]));
    }

    public static function receiptBindingCollision(string $packageId): self
    {
        return new self(__('Incoming already binds Data Share package :package to a different offer, source, scope, target, or byte sequence.', [
            'package' => $packageId,
        ]));
    }

    public static function receiveFailed(string $path): self
    {
        return new self(__('The Data Share package could not be received into :path.', ['path' => $path]));
    }

    public static function receiveInProgress(string $packageId): self
    {
        return new self(__('Data Share package :package is already being admitted to Incoming. Retry after the current verification finishes.', [
            'package' => $packageId,
        ]));
    }

    public static function duplicatePrimaryKey(string $table): self
    {
        return new self(__('Table :table contains a duplicate primary key in its package payload.', ['table' => $table]));
    }

    public static function scalarTooLarge(string $table, string $column, int $max): self
    {
        return new self(__('Table :table column :column exceeds the :max byte transfer scalar limit.', [
            'table' => $table,
            'column' => $column,
            'max' => $max,
        ]));
    }

    public static function tooManyRecords(int $max): self
    {
        return new self(__('The Data Share selection exceeds the :max record limit.', ['max' => $max]));
    }

    public static function tooManyTables(int $max): self
    {
        return new self(__('The Data Share selection exceeds the :max table limit.', ['max' => $max]));
    }

    public static function packageTooLarge(int $max): self
    {
        return new self(__('The Data Share package exceeds the :max byte limit.', ['max' => $max]));
    }

    public static function previewChanged(): self
    {
        return new self(__('Source data changed after preview. Preview the table selection again before exporting.'));
    }

    public static function unsafeDisk(string $disk): self
    {
        return new self(__('Data Share disk :disk is public. Configure a private filesystem disk before exporting data.', ['disk' => $disk]));
    }

    public static function storeFailed(string $path): self
    {
        return new self(__('The Data Share package could not be written to :path.', ['path' => $path]));
    }
}
