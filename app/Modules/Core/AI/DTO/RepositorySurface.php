<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

final readonly class RepositorySurface
{
    public function __construct(
        public string $target,
        public string $rootPath,
        public string $relativeRoot,
        public ?string $extensionSlug = null,
    ) {}

    public function isCore(): bool
    {
        return $this->target === 'core';
    }
}
