<?php

namespace App\Base\Foundation\Contracts;

interface DomainRuntimeReloader
{
    /**
     * Reload any long-lived runtime state after domain code changes.
     *
     * @return list<string>
     */
    public function reloadAfterDomainChange(): array;
}
