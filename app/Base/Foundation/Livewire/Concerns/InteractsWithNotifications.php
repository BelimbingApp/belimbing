<?php

namespace App\Base\Foundation\Livewire\Concerns;

/**
 * Emits notifications for same-page feedback.
 *
 * Use this for feedback on actions where the user stays on the page and the
 * result is obvious (toggles, inline-edit saves, row actions, reorder): a
 * top-right notification confirms without prepending an alert to the page top
 * or shifting layout. For persistent page-level context (validation summaries,
 * standing warnings) keep an inline `<x-ui.alert>`; for post-redirect landing
 * banners keep the `success`/`error` session flash rendered by
 * `<x-ui.session-flash>`.
 *
 * Persistence is severity-tiered and owned by `<x-ui.notification-hub>`:
 * `error`/`warning` stay until the user dismisses them (must not be missed);
 * `success`/`info` auto-dismiss after a short dwell, with a close button always
 * present. Pass an explicit `$duration` (ms) to force a timer on any variant.
 *
 * The dispatched `notify` browser event is caught by the global
 * `<x-ui.notification-hub>` in the app layout. Because it rides the Livewire
 * dispatch bus (not the session), delivery is immediate on the current render
 * and does not survive a redirect — which is exactly the same-page contract.
 */
trait InteractsWithNotifications
{
    public function notify(string $message, string $variant = 'success', ?int $duration = null): void
    {
        $this->dispatch('notify', message: $message, variant: $variant, duration: $duration);
    }

    public function notifySuccess(string $message): void
    {
        $this->notify($message);
    }

    public function notifyError(string $message): void
    {
        $this->notify($message, 'error');
    }

    public function notifyWarning(string $message): void
    {
        $this->notify($message, 'warning');
    }
}
