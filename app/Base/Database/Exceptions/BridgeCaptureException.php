<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class BridgeCaptureException extends RuntimeException
{
    public static function noSelection(): self
    {
        return new self(__('Select at least one row to capture.'));
    }

    public static function tooManySelected(int $max): self
    {
        return new self(__('Selection exceeds the maximum of :max rows per capture.', ['max' => $max]));
    }

    public static function noPrimaryKey(string $table): self
    {
        return new self(__('Table :table has no single-column primary key; row capture is not available.', ['table' => $table]));
    }

    public static function closureTooLarge(int $max): self
    {
        return new self(__('The dependency closure exceeds :max rows. Narrow the selection.', ['max' => $max]));
    }

    public static function closureTooDeep(int $max): self
    {
        return new self(__('The dependency closure exceeds :max levels of references. Narrow the selection.', ['max' => $max]));
    }

    public static function selectionChanged(): self
    {
        return new self(__('One or more selected rows no longer exist. Review the selection again.'));
    }

    public static function previewChanged(): self
    {
        return new self(__('The selected rows or their dependencies changed after preview. Review the updated preview before creating a package.'));
    }

    public static function scalarTooLarge(string $table, string $column, int $max): self
    {
        return new self(__('The value in :table.:column exceeds the :max byte capture limit.', [
            'table' => $table,
            'column' => $column,
            'max' => $max,
        ]));
    }

    public static function packageTooLarge(int $max): self
    {
        return new self(__('The diagnostic package exceeds the :max byte limit. Narrow the selection.', ['max' => $max]));
    }

    public static function unsafeDisk(string $disk): self
    {
        return new self(__('Bridge disk :disk is public. Configure a private filesystem disk before capturing rows.', ['disk' => $disk]));
    }

    public static function storeFailed(string $path): self
    {
        return new self(__('The diagnostic package could not be written to :path.', ['path' => $path]));
    }
}
