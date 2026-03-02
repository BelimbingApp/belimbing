<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Htmx\Concerns;

use App\Base\Htmx\HtmxRequest;
use App\Base\Htmx\HtmxResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Provides HTMX-aware helpers for controllers.
 *
 * Mix into any controller that needs to detect HTMX requests,
 * return HTML fragments, or emit HX-* response headers.
 */
trait InteractsWithHtmx
{
    /**
     * Wrap the current request in an HtmxRequest for HTMX header access.
     */
    protected function htmxRequest(Request $request): HtmxRequest
    {
        return new HtmxRequest($request);
    }

    /**
     * Create a new HtmxResponse builder.
     */
    protected function htmxResponse(): HtmxResponse
    {
        return new HtmxResponse;
    }

    /**
     * Return a 204 No Content response with optional HTMX headers.
     *
     * Useful for delete/action endpoints that need no body swap,
     * but may still trigger client-side events or redirects.
     */
    protected function htmxNoContent(?HtmxResponse $htmx = null): Response
    {
        $response = response()->noContent();
        $htmx?->applyTo($response);

        return $response;
    }

    /**
     * Determine whether the current request was issued by HTMX.
     */
    protected function isHtmxRequest(Request $request): bool
    {
        return $request->header('HX-Request') === 'true';
    }
}
