<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Attributes;

/**
 * Controls field visibility for Lara's page snapshot.
 *
 * Applied to Livewire component public properties to control how
 * FieldVisibilityResolver treats them during snapshot building.
 *
 * Properties without this attribute follow default rules:
 * - Public Livewire properties are visible by default
 * - Properties named password, secret, token, api_key → masked
 * - Properties typed as SensitiveParameterValue → masked
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class LaraVisible
{
    public function __construct(
        public readonly bool $visible = true,
        public readonly bool $masked = false,
    ) {}
}
