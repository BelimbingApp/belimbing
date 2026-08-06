<?php
namespace App\Core\AI\DTO;

final readonly class RepositorySurface
{
    public function __construct(
        public string $target,
        public string $rootPath,
        public string $relativeRoot,
        public ?string $domainSlug = null,
        public ?string $extensionSlug = null,
    ) {}

    public function isPlatform(): bool
    {
        return $this->target === 'platform';
    }

    /**
     * Compatibility for callers compiled against the former surface name.
     */
    public function isCore(): bool
    {
        return $this->isPlatform() || $this->target === 'core';
    }
}
