<?php

namespace App\Base\Foundation\Console\Commands\Concerns;

/**
 * Octane's server commands subscribe to POSIX signals unconditionally, which
 * fatals on native Windows PHP where the pcntl constants do not exist.
 * Symfony never dispatches signals without pcntl anyway, so subscribing to
 * none is behaviorally identical there.
 */
trait SkipsSignalsWithoutPcntl
{
    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? parent::getSubscribedSignals() : [];
    }
}
