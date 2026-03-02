<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Htmx;

use Illuminate\Http\Response;

/**
 * Builder for HTMX-specific response headers.
 *
 * Provides a fluent interface for attaching HX-* response headers
 * to a Laravel response. Use this to drive client-side HTMX behavior
 * from controllers without leaking header string literals everywhere.
 */
final class HtmxResponse
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * Instruct HTMX to perform a client-side redirect.
     */
    public function redirect(string $url): static
    {
        $this->headers['HX-Redirect'] = $url;

        return $this;
    }

    /**
     * Push a URL onto the browser history stack.
     */
    public function pushUrl(string $url): static
    {
        $this->headers['HX-Push-Url'] = $url;

        return $this;
    }

    /**
     * Replace the current URL in the browser history.
     */
    public function replaceUrl(string $url): static
    {
        $this->headers['HX-Replace-Url'] = $url;

        return $this;
    }

    /**
     * Trigger a full page refresh.
     */
    public function refresh(): static
    {
        $this->headers['HX-Refresh'] = 'true';

        return $this;
    }

    /**
     * Override the target element for the response swap.
     */
    public function retarget(string $selector): static
    {
        $this->headers['HX-Retarget'] = $selector;

        return $this;
    }

    /**
     * Override the swap strategy for this response.
     */
    public function reswap(string $strategy): static
    {
        $this->headers['HX-Reswap'] = $strategy;

        return $this;
    }

    /**
     * Trigger one or more client-side events after the swap.
     *
     * @param  string|array<string, mixed>  $events  Event name or map of event→detail
     */
    public function trigger(string|array $events): static
    {
        $this->headers['HX-Trigger'] = is_string($events) ? $events : json_encode($events);

        return $this;
    }

    /**
     * Trigger client-side events after the DOM has settled.
     *
     * @param  string|array<string, mixed>  $events
     */
    public function triggerAfterSettle(string|array $events): static
    {
        $this->headers['HX-Trigger-After-Settle'] = is_string($events) ? $events : json_encode($events);

        return $this;
    }

    /**
     * Trigger client-side events after the swap is complete.
     *
     * @param  string|array<string, mixed>  $events
     */
    public function triggerAfterSwap(string|array $events): static
    {
        $this->headers['HX-Trigger-After-Swap'] = is_string($events) ? $events : json_encode($events);

        return $this;
    }

    /**
     * Apply all accumulated headers to a response.
     */
    public function applyTo(Response $response): Response
    {
        foreach ($this->headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * Return the accumulated headers array (for manual application).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
