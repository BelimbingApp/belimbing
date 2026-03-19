<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Listeners;

use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Notifications\TransitionNotification;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Collection;

/**
 * Send notifications to relevant parties after a workflow transition.
 *
 * Reads the `notifications` JSON config from the target StatusConfig
 * to determine recipients and channels, then dispatches a
 * TransitionNotification to each unique, notifiable recipient
 * (excluding the actor who triggered the transition).
 */
class SendTransitionNotification
{
    /**
     * Handle the TransitionCompleted event.
     */
    public function handle(TransitionCompleted $event): void
    {
        $targetStatus = StatusConfig::query()
            ->where('flow', $event->flow)
            ->where('code', $event->transition->to_code)
            ->first();

        if ($targetStatus === null) {
            return;
        }

        $notificationConfig = $targetStatus->notifications;

        if (empty($notificationConfig) || empty($notificationConfig['on_enter'])) {
            return;
        }

        $channels = $notificationConfig['channels'] ?? ['database'];
        $recipients = $this->collectRecipients($notificationConfig['on_enter'], $event);

        $actorId = $event->context->actor->id;

        $recipients = $recipients
            ->unique(fn (object $user): int => (int) $user->getKey())
            ->filter(fn (object $user): bool => (int) $user->getKey() !== $actorId);

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new TransitionNotification(
            flow: $event->flow,
            model: $event->model,
            transition: $event->transition,
            history: $event->history,
            channels: $channels,
        );

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }

    /**
     * Collect notifiable recipients based on the on_enter configuration.
     *
     * @param  array<int, string>  $onEnter  Recipient types (e.g., 'reporter', 'assignee', 'actor')
     * @param  TransitionCompleted  $event  The transition event
     * @return Collection<int, object>
     */
    private function collectRecipients(array $onEnter, TransitionCompleted $event): Collection
    {
        $recipients = new Collection;

        foreach ($onEnter as $type) {
            match ($type) {
                'reporter' => $this->addModelRelation($recipients, $event->model, 'reporter'),
                'assignee' => $this->addModelRelation($recipients, $event->model, 'assignee'),
                'actor' => $this->addActorUser($recipients, $event->context->actor->id),
                'pic' => null, // Future: resolve PIC users from StatusConfig
                default => null,
            };
        }

        return $recipients;
    }

    /**
     * Add a user from a model relation if the relation exists and returns a notifiable user.
     *
     * @param  Collection<int, object>  $recipients
     */
    private function addModelRelation(Collection $recipients, object $model, string $relation): void
    {
        if (! method_exists($model, $relation)) {
            return;
        }

        $user = $model->{$relation};

        if ($user !== null && method_exists($user, 'notify')) {
            $recipients->push($user);
        }
    }

    /**
     * Add the actor user by ID if they are notifiable.
     *
     * @param  Collection<int, object>  $recipients
     * @param  int  $actorId  The actor's user ID
     */
    private function addActorUser(Collection $recipients, int $actorId): void
    {
        $user = User::query()->find($actorId);

        if ($user !== null && method_exists($user, 'notify')) {
            $recipients->push($user);
        }
    }
}
