<?php

namespace App\Base\Workflow\Contracts;

/**
 * Workflow participant models that can present themselves in a notification.
 *
 * Implemented by flow models (tickets, NCRs, ...) so that transition
 * notifications carry a human-readable title and a deep link without the
 * notification layer knowing module routes.
 */
interface PresentsWorkflowNotifications
{
    /**
     * Short human title for notification lists (e.g. the ticket title).
     */
    public function workflowNotificationTitle(): string;

    /**
     * Absolute URL of the record's detail page, when it has one.
     */
    public function workflowNotificationUrl(): ?string;
}
