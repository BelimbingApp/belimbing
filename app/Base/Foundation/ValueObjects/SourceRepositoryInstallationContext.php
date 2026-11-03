<?php

namespace App\Base\Foundation\ValueObjects;

/**
 * Immutable identity of a source checkout while its installation is running.
 */
final readonly class SourceRepositoryInstallationContext
{
    public function __construct(
        public string $path,
        public string $kind,
        public string $folder,
        public string $repositoryUrl,
    ) {}
}
