<?php

namespace App\Modules\Commerce\Plugins\Contracts;

interface CommerceReadinessContributor
{
    /**
     * Stable contribution identifier, e.g. `ham.auto-parts.identifiers`.
     */
    public function id(): string;
}
