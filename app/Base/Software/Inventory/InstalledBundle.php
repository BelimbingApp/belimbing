<?php

namespace App\Base\Software\Inventory;

/**
 * A Distribution Bundle as it appears on the Software Inventory: the delivery unit
 * an operator installs, updates, or removes, grouping the Modules it contains.
 *
 * Built by SoftwareInventoryService by joining git-backed bundle discovery
 * (DistributionBundleRepository) with module manifests (ModuleManifestReader), so
 * the installed view groups by delivery unit first and shows Modules as detail.
 * Immutable value object.
 */
final readonly class InstalledBundle
{
    public const KIND_PLATFORM = 'platform';

    public const KIND_BUSINESS_DOMAIN = 'business-domain';

    public const KIND_EXTENSION = 'extension';

    public const KIND_SLOT_IMPLEMENTATION = 'slot-implementation';

    /**
     * @param  array{dirty: int, ahead: int, behind: int}  $workingTree
     * @param  array<string, mixed>|null  $commit
     * @param  list<InstalledModule>  $modules
     * @param  list<array{issue: string, requiring: string, requiring_module: string, required: string, constraint: string, installed_version?: string}>  $dependencyIssues
     * @param  list<ContributionSummary>  $contributions  runtime contributions this bundle delivers to host seams
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $kind,
        public string $path,
        public bool $hasGit,
        public ?string $repo,
        public ?string $branch,
        public ?array $commit,
        public array $workingTree,
        public bool $disabled,
        public array $modules,
        public array $dependencyIssues = [],
        public ?string $lifecycleName = null,
        public array $contributions = [],
    ) {}

    public function moduleCount(): int
    {
        return count($this->modules);
    }

    public function isDirty(): bool
    {
        return $this->workingTree['dirty'] > 0;
    }

    public function unpushed(): int
    {
        return $this->workingTree['ahead'];
    }

    public function behind(): int
    {
        return $this->workingTree['behind'];
    }

    public function hasDependencyIssues(): bool
    {
        return $this->dependencyIssues !== [];
    }

    public function hasContributions(): bool
    {
        return $this->contributions !== [];
    }

    /**
     * Whether this bundle exposes domain lifecycle actions (enable/disable/uninstall).
     * Only business domains and extensions are installed/removed by operators; the
     * platform and nested module/slot roots are not.
     */
    public function isLifecycleManaged(): bool
    {
        return $this->lifecycleName !== null
            && in_array($this->kind, [self::KIND_BUSINESS_DOMAIN, self::KIND_EXTENSION], true);
    }
}
