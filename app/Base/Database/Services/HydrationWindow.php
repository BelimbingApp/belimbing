<?php

namespace App\Base\Database\Services;

use Closure;

/**
 * One unit of work the hydration guard is counting: the process root, an
 * HTTP request, a queued job, or a console command.
 *
 * @internal Owned by HydrationGuard.
 */
final class HydrationWindow
{
    public const KIND_PROCESS = 'process';

    public const KIND_HTTP = 'http';

    public const KIND_JOB = 'job';

    public const KIND_COMMAND = 'command';

    public int $count = 0;

    public bool $reported = false;

    /** @var array<class-string, int> */
    public array $models = [];

    /**
     * @param  string|(Closure(): string)|null  $label  A closure is resolved on first read, only when reporting.
     * @param  Closure(): array<string, mixed>|null  $describe  Resolved lazily, only when reporting.
     * @param  int|null  $identity  Distinguishes nested jobs (spl_object_id of the job).
     */
    public function __construct(
        public readonly string $kind,
        private string|Closure|null $label,
        public readonly ?Closure $describe = null,
        public readonly ?int $identity = null,
    ) {}

    public function label(): ?string
    {
        if ($this->label instanceof Closure) {
            $this->label = ($this->label)();
        }

        return $this->label;
    }
}
