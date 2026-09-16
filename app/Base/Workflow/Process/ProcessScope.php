<?php

namespace App\Base\Workflow\Process;

final readonly class ProcessScope
{
    private function __construct(public string $type, public ?int $tenantId) {}

    public static function tenant(int $tenantId): self
    {
        if ($tenantId < 1) {
            throw new ProcessCoordinationException('A tenant process scope needs a positive tenant ID.');
        }

        return new self('tenant', $tenantId);
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    /** Compatibility scope for pre-tenancy internal callers; never human-readable. */
    public static function unresolved(): self
    {
        return new self('unresolved', null);
    }
}
