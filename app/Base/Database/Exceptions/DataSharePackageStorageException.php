<?php

namespace App\Base\Database\Exceptions;

class DataSharePackageStorageException extends DataSharePackageException
{
    public static function temporaryStorageUnavailable(): self
    {
        return new self(__('Temporary Data Share package storage could not be allocated.'));
    }

    public static function temporaryStorageOpenFailed(): self
    {
        return new self(__('Temporary Data Share package storage could not be opened.'));
    }

    public static function payloadReopenFailed(): self
    {
        return new self(__('A temporary Data Share payload could not be reopened.'));
    }

    public static function payloadInspectionFailed(): self
    {
        return new self(__('A temporary Data Share payload could not be inspected.'));
    }

    public static function payloadWriteFailed(): self
    {
        return new self(__('A canonical Data Share payload could not be written.'));
    }
}
