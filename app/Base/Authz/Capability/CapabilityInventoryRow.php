<?php

namespace App\Base\Authz\Capability;

/**
 * One capability as an operator needs to see it.
 *
 * `holders` is null, never zero, when the capability was rejected by the
 * catalog: it is denied to everybody at runtime, so counting the principals
 * whose roles grant it would report an access nobody actually has.
 */
final readonly class CapabilityInventoryRow
{
    /**
     * @param  list<string>  $modules
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $capability,
        public array $modules,
        public bool $conflicted,
        public array $roles,
        public ?int $holders,
        public ?string $rejectedReason,
    ) {}
}
