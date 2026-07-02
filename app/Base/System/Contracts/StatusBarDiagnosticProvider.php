<?php

namespace App\Base\System\Contracts;

use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;

interface StatusBarDiagnosticProvider
{
    public const CONTAINER_TAG = 'blb.status-bar.diagnostic-providers';

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable;
}
