<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Auth mechanism used by a provider during initial setup and at runtime.
 *
 * Stored on `ai_providers.auth_type` at governance time so the UI and
 * runtime can dispatch without re-reading the catalog overlay.
 */
enum AuthType: string
{
    case ApiKey = 'api_key';
    case Local = 'local';
    case DeviceFlow = 'device_flow';
    case OAuth = 'oauth';
    case Custom = 'custom';

    /**
     * Whether the API key field is required during initial provider setup.
     */
    public function requiresApiKey(): bool
    {
        return match ($this) {
            self::ApiKey, self::Custom, self::DeviceFlow => true,
            self::Local, self::OAuth => false,
        };
    }
}
