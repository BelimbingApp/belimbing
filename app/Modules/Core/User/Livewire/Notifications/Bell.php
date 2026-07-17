<?php

namespace App\Modules\Core\User\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Top-bar notification bell: unread badge and the most recent
 * database notifications for the signed-in user.
 *
 * Notifications carry presentation hints in their data payload
 * (`title`, `url`, `body` / `transition_label` + `comment`); this
 * component only arranges them.
 */
class Bell extends Component
{
    private const RECENT_LIMIT = 15;

    /**
     * Mark one notification read, then follow its deep link if it has one.
     */
    public function visit(string $notificationId): void
    {
        $notification = Auth::user()
            ?->notifications()
            ->whereKey($notificationId)
            ->first();

        if ($notification === null) {
            return;
        }

        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if (is_string($url) && $url !== '') {
            $this->redirect($url, navigate: true);
        }
    }

    /**
     * Mark every unread notification read.
     */
    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications()->update(['read_at' => Carbon::now()]);
    }

    public function render(): View
    {
        $user = Auth::user();

        $notifications = $user?->notifications()->limit(self::RECENT_LIMIT)->get() ?? collect();

        return view('livewire.notifications.bell', [
            'items' => $notifications->map(fn (DatabaseNotification $notification): array => $this->present($notification)),
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
        ]);
    }

    /**
     * Flatten a notification into the fields the dropdown renders.
     *
     * @return array{id: string, title: string, body: string, url: string|null, read: bool, time: Carbon|null}
     */
    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        $title = $data['title'] ?? null;

        if (! is_string($title) || $title === '') {
            $title = Str::headline(class_basename($notification->type));
        }

        $body = $data['body'] ?? null;

        if (! is_string($body) || $body === '') {
            $label = $data['transition_label'] ?? null;
            $comment = $data['comment'] ?? null;
            $body = collect([$label, $comment])
                ->filter(fn ($part): bool => is_string($part) && $part !== '')
                ->implode(' — ');
        }

        $url = $data['url'] ?? null;

        return [
            'id' => (string) $notification->getKey(),
            'title' => $title,
            'body' => $body,
            'url' => is_string($url) && $url !== '' ? $url : null,
            'read' => $notification->read(),
            'time' => $notification->created_at,
        ];
    }
}
