<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Exceptions;

use RuntimeException;

class InventoryStorageException extends RuntimeException
{
    public static function photoStoreFailed(): self
    {
        return new self('Inventory photo could not be stored.');
    }
}
