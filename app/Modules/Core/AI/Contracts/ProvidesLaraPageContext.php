<?php
namespace App\Modules\Core\AI\Contracts;

use App\Modules\Core\AI\DTO\PageContext;

/**
 * Livewire page components implement this to declare what Lara sees.
 *
 * Pages that implement this contract get full page metadata in Lara's
 * system prompt. Pages without the contract get only a minimal fallback
 * derived from the route name.
 */
interface ProvidesLaraPageContext
{
    /**
     * Build the page context DTO from this component's current state.
     */
    public function pageContext(): PageContext;
}
