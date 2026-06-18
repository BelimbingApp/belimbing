<?php

namespace Tests\Support;

use App\Base\Foundation\Contracts\DomainRuntimeReloader;

final class FakeDomainRuntimeReloader implements DomainRuntimeReloader
{
    public int $calls = 0;

    /**
     * @param  list<string>  $log
     */
    public function __construct(
        private readonly array $log = ['Domain runtime reload scheduled in the background.'],
    ) {}

    /**
     * @return list<string>
     */
    public function reloadAfterDomainChange(): array
    {
        $this->calls++;

        return $this->log;
    }
}
