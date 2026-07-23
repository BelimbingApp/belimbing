<?php

namespace App\Base\System\Http\Middleware;

use App\Base\System\Services\RuntimeConfigurationApplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApplyRuntimeConfiguration
{
    public function __construct(
        private RuntimeConfigurationApplier $configuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->configuration->apply();

        return $next($request);
    }
}
