<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class DataShareImportException extends RuntimeException
{
    public static function unsafeDisk(string $disk): self
    {
        return new self(__('Data Share disk :disk is public. Configure a private filesystem disk before receiving packages.', ['disk' => $disk]));
    }

    public static function invalidSourcePath(): self
    {
        return new self(__('Choose a diagnostic package from the configured Data Share diagnostics path.'));
    }

    public static function sourceMissing(string $path): self
    {
        return new self(__('Diagnostic package :path was not found on the Data Share disk.', ['path' => $path]));
    }

    public static function packageTooLarge(int $max): self
    {
        return new self(__('The diagnostic package exceeds the :max byte limit.', ['max' => $max]));
    }

    public static function invalidPackage(string $reason): self
    {
        return new self(__('The diagnostic package is invalid: :reason', ['reason' => $reason]));
    }

    public static function receiveFailed(string $path): self
    {
        return new self(__('The diagnostic package could not be received into :path.', ['path' => $path]));
    }

    public static function packageIdCollision(string $packageId): self
    {
        return new self(__('Incoming already contains different bytes for package :id.', ['id' => $packageId]));
    }

    public static function incomingMissing(string $packageId): self
    {
        return new self(__('Incoming package :id or its receipt was not found.', ['id' => $packageId]));
    }

    public static function receiptMismatch(string $packageId): self
    {
        return new self(__('Incoming package :id no longer matches its receipt hash.', ['id' => $packageId]));
    }

    public static function unsupportedTable(string $table): self
    {
        return new self(__('Destination table :table is missing or not registered.', ['table' => $table]));
    }

    public static function incompatibleSchema(string $table, string $reason): self
    {
        return new self(__('Destination table :table is incompatible with the package: :reason', [
            'table' => $table,
            'reason' => $reason,
        ]));
    }

    public static function redactedRequiredColumn(string $table, string $column): self
    {
        return new self(__('Cannot insert into :table because redacted required column :column has no destination value or default.', [
            'table' => $table,
            'column' => $column,
        ]));
    }

    public static function previewChanged(): self
    {
        return new self(__('The Incoming package changed after inspection. Inspect it again before applying.'));
    }
}
