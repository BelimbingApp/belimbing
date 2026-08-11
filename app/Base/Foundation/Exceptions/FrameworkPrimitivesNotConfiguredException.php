<?php

namespace App\Base\Foundation\Exceptions;

final class FrameworkPrimitivesNotConfiguredException extends \RuntimeException
{
    public static function missingPlatformOperatorCompany(): self
    {
        return new self(
            'The platform-operator primary company is not configured. Provide PLATFORM_OPERATOR_COMPANY_NAME (and optional PLATFORM_OPERATOR_COMPANY_CODE) during installation/setup.'
        );
    }

    public static function missingAdminBootstrap(): self
    {
        return new self(
            'Admin user is not configured. Provide an admin bootstrap payload during installation/setup.'
        );
    }

    public static function bootstrapAdminBelongsToAnotherCompany(string $email): self
    {
        return new self(
            "The bootstrap admin [{$email}] already belongs to another company and cannot be reassigned during platform provisioning."
        );
    }
}
