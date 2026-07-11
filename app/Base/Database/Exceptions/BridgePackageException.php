<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class BridgePackageException extends RuntimeException
{
    public static function invalidPackage(string $reason): self
    {
        return new self(__('The Data Bridge package is invalid: :reason', ['reason' => $reason]));
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(__('Unsupported Data Bridge package format: :format.', ['format' => $format]));
    }

    public static function wrongTarget(string $expected, string $actual): self
    {
        return new self(__('The Data Bridge package targets :actual, not this instance :expected.', [
            'expected' => $expected,
            'actual' => $actual,
        ]));
    }

    public static function expired(string $packageId): self
    {
        return new self(__('Data Bridge package :package has expired.', ['package' => $packageId]));
    }

    public static function payloadHashMismatch(string $table): self
    {
        return new self(__('Data Bridge payload :table does not match its manifest hash.', ['table' => $table]));
    }

    public static function schemaMismatch(string $table): self
    {
        return new self(__('Destination schema is not compatible with Data Bridge table :table.', ['table' => $table]));
    }

    public static function recordLineTooLarge(int $max): self
    {
        return new self(__('A Data Bridge record exceeds the :max byte line limit.', ['max' => $max]));
    }

    public static function packageIdCollision(string $packageId): self
    {
        return new self(__('Incoming already contains different bytes for Data Bridge package :package.', ['package' => $packageId]));
    }

    public static function receiveFailed(string $path): self
    {
        return new self(__('The Data Bridge package could not be received into :path.', ['path' => $path]));
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
        return new self(__('The Data Bridge selection exceeds the :max record limit.', ['max' => $max]));
    }

    public static function tooManyTables(int $max): self
    {
        return new self(__('The Data Bridge selection exceeds the :max table limit.', ['max' => $max]));
    }

    public static function packageTooLarge(int $max): self
    {
        return new self(__('The Data Bridge package exceeds the :max byte limit.', ['max' => $max]));
    }

    public static function previewChanged(): self
    {
        return new self(__('Source data changed after preview. Preview the table selection again before exporting.'));
    }

    public static function unsafeDisk(string $disk): self
    {
        return new self(__('Bridge disk :disk is public. Configure a private filesystem disk before exporting data.', ['disk' => $disk]));
    }

    public static function storeFailed(string $path): self
    {
        return new self(__('The Data Bridge package could not be written to :path.', ['path' => $path]));
    }
}
