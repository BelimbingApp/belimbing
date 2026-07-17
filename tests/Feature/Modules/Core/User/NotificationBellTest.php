<?php

use App\Modules\Core\User\Livewire\Notifications\Bell;
use App\Modules\Core\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Livewire\Livewire;

test('the notification bell scopes reads and mutations to the signed-in user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $user->notify(new BellTestNotification('Mine', '/mine'));
    $user->notify(new BellTestNotification('Mine too', '/mine-too'));
    $other->notify(new BellTestNotification('Not mine', '/not-mine'));

    $mine = $user->notifications()->where('data->title', 'Mine')->firstOrFail();
    $notMine = $other->notifications()->firstOrFail();

    $component = Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertViewHas('unreadCount', 2)
        ->assertViewHas(
            'items',
            fn ($items): bool => $items->pluck('title')->sort()->values()->all() === ['Mine', 'Mine too'],
        );

    $component->call('visit', $notMine->id);
    expect($notMine->fresh()->read_at)->toBeNull();

    $component->call('visit', $mine->id);
    expect($mine->fresh()->read_at)->not->toBeNull();

    $component->call('markAllRead');
    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);
});

test('the visible unread badge is included in the bell accessible name', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 12) as $index) {
        $user->notify(new BellTestNotification('Notice '.$index, '/notice/'.$index));
    }

    Livewire::actingAs($user)
        ->test(Bell::class)
        ->assertSee('aria-label="12 unread notifications"', false)
        ->assertSeeText('12');
});

class BellTestNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'body' => 'Notification body',
        ];
    }
}
