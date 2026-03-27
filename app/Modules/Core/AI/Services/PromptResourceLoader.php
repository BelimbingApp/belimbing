<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbConfigurationException;

class PromptResourceLoader
{
    public function load(string $path, string $label, string $resource): string
    {
        if (! is_file($path)) {
            throw new BlbConfigurationException(
                $label.' file missing: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_MISSING,
                ['path' => $path, 'resource' => $resource]
            );
        }

        $content = file_get_contents($path);

        if (! is_string($content)) {
            throw new BlbConfigurationException(
                'Failed to read '.$label.' file: '.$path,
                BlbErrorCode::LARA_PROMPT_RESOURCE_UNREADABLE,
                ['path' => $path, 'resource' => $resource]
            );
        }

        return trim($content);
    }
}
