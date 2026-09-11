<?php

namespace App\Base\Foundation\Http\Controllers;

use App\Base\Foundation\Services\CompositionReport;
use Illuminate\Http\JsonResponse;

/**
 * Operator read of the composed application: mounted modules, boot order,
 * and the commit each Domain checkout is at (#623). Read-only; the route
 * carries the platform-operator guard because the checkout refs and module
 * paths it names describe the installation, not any tenant's data.
 */
class CompositionController
{
    public function __invoke(CompositionReport $report): JsonResponse
    {
        return response()->json($report->build());
    }
}
