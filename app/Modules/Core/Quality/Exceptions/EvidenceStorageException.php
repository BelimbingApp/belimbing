<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Exceptions;

use RuntimeException;

class EvidenceStorageException extends RuntimeException
{
    public static function storeFailed(): self
    {
        return new self('Quality evidence file store failed.');
    }
}

