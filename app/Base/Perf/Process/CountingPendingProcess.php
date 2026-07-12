<?php

namespace App\Base\Perf\Process;

use App\Base\Perf\Services\PerformanceCollector;
use Illuminate\Process\Factory;
use Illuminate\Process\PendingProcess;

final class CountingPendingProcess extends PendingProcess
{
    public function __construct(Factory $factory, private readonly PerformanceCollector $collector)
    {
        parent::__construct($factory);
    }

    public function run(array|string|null $command = null, ?callable $output = null)
    {
        $startedAt = hrtime(true);

        try {
            return parent::run($command, $output);
        } finally {
            $this->collector->recordProcess((hrtime(true) - $startedAt) / 1e6);
        }
    }

    public function start(array|string|null $command = null, ?callable $output = null)
    {
        // Async processes outlive this call; count the spawn, not a duration.
        $this->collector->recordProcess(0.0);

        return parent::start($command, $output);
    }
}
