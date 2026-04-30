<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Exceptions;

final class FrameworkPrimitivesNotConfiguredException extends \RuntimeException
{
    public static function missingLicenseeCompany(): self
    {
        return new self(
            'Licensee company is not configured. Provide LICENSEE_COMPANY_NAME (and optional LICENSEE_COMPANY_CODE) during installation/setup.'
        );
    }

    public static function missingAdminBootstrap(): self
    {
        return new self(
            'Admin user is not configured. Provide an admin bootstrap payload during installation/setup.'
        );
    }
}

