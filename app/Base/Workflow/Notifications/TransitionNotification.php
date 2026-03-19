<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Notifications;

use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Notification sent to relevant parties when a workflow transition completes.
 *
 * Stores transition metadata (flow, statuses, actor, comment) in the
 * database notifications table for in-app consumption.
 */
class TransitionNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $flow  The workflow flow identifier
     * @param  Model  $model  The workflow participant model
     * @param  StatusTransition  $transition  The transition edge that fired
     * @param  StatusHistory  $history  The history record created by the transition
     * @param  array<int, string>  $channels  Laravel notification channels to use
     */
    public function __construct(
        public readonly string $flow,
        public readonly Model $model,
        public readonly StatusTransition $transition,
        public readonly StatusHistory $history,
        public readonly array $channels = ['database'],
    ) {}

    /**
     * Determine which channels the notification should be delivered on.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Build the array representation for the database notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'flow' => $this->flow,
            'model_type' => get_class($this->model),
            'model_id' => $this->model->getKey(),
            'from_status' => $this->transition->from_code,
            'to_status' => $this->transition->to_code,
            'transition_label' => $this->transition->resolveLabel(),
            'actor_id' => $this->history->actor_id,
            'comment' => $this->history->comment,
        ];
    }
}
