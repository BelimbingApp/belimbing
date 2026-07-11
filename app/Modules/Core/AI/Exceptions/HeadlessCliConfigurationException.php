<?php

namespace App\Modules\Core\AI\Exceptions;

use App\Base\Foundation\Exceptions\BlbConfigurationException;

final class HeadlessCliConfigurationException extends BlbConfigurationException
{
    public static function unknownProvider(string $provider): self
    {
        return new self('Unknown headless provider ['.$provider.']; add it to ai-headless.providers.');
    }
}
