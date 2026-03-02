<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Htmx;

use Illuminate\Http\Request;

/**
 * Wraps an HTTP request to expose HTMX-specific headers.
 *
 * Provides typed accessors for all standard HX-* request headers,
 * making controller code self-documenting and IDE-friendly.
 */
final class HtmxRequest
{
    public function __construct(private readonly Request $request) {}

    /** Whether the request was made by HTMX. */
    public function isHtmx(): bool
    {
        return $this->request->header('HX-Request') === 'true';
    }

    /** Whether the request is a boosted navigation. */
    public function isBoosted(): bool
    {
        return $this->request->header('HX-Boosted') === 'true';
    }

    /** The id of the target element, or null. */
    public function target(): ?string
    {
        return $this->request->header('HX-Target');
    }

    /** The id/name of the trigger element, or null. */
    public function triggerId(): ?string
    {
        return $this->request->header('HX-Trigger');
    }

    /** The name of the trigger element, or null. */
    public function triggerName(): ?string
    {
        return $this->request->header('HX-Trigger-Name');
    }

    /** The current URL of the browser, or null. */
    public function currentUrl(): ?string
    {
        return $this->request->header('HX-Current-URL');
    }

    /** The URL of the prompt response (when using hx-prompt), or null. */
    public function promptResponse(): ?string
    {
        return $this->request->header('HX-Prompt');
    }
}
