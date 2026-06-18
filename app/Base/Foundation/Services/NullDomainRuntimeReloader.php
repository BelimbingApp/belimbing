<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\DomainRuntimeReloader;

final class NullDomainRuntimeReloader implements DomainRuntimeReloader
{
    /**
     * @return list<string>
     */
    public function reloadAfterDomainChange(): array
    {
        return [];
    }
}
