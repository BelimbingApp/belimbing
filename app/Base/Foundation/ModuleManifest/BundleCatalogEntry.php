<?php

namespace App\Base\Foundation\ModuleManifest;

use DateTimeImmutable;

/**
 * One bundle discovered from the BelimbingApp catalog.
 *
 * Immutable value object; the catalog service builds these from cached
 * rows and returns lists to the UI.
 */
final readonly class BundleCatalogEntry
{
    /**
     * @param  array<string, mixed>  $manifest  Raw extra.blb block as parsed JSON.
     */
    public function __construct(
        public string $repoName,
        public string $htmlUrl,
        public string $composerName,
        public string $moduleIdentifier,
        public string $version,
        public string $description,
        public ?string $defaultBranch,
        public ?string $defaultBranchSha,
        public DateTimeImmutable $fetchedAt,
        public array $manifest = [],
    ) {}

    /**
     * Suggested clone command for the canonical People-domain layout.
     * Phase 3 surfaces this text in the UI for the operator to copy.
     */
    public function suggestedInstallCommand(): string
    {
        $domain = explode('/', $this->moduleIdentifier)[0] ?? '';
        $module = explode('/', $this->moduleIdentifier)[1] ?? $this->repoName;
        $path = $domain !== '' && $module !== ''
            ? "app/Modules/{$this->capitalise($domain)}/{$this->capitalise($module)}"
            : "app/Modules/{$this->repoName}";

        return "git clone {$this->htmlUrl}.git {$path}";
    }

    private function capitalise(string $segment): string
    {
        $parts = preg_split('/[-_]/', $segment) ?: [$segment];

        return implode('', array_map('ucfirst', array_map('strtolower', $parts)));
    }
}
