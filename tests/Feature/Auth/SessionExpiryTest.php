<?php

use App\Modules\Core\User\Livewire\Auth\Login;
use Livewire\Livewire;

test('login page shows session expired notice when flashed', function (): void {
    $this->withSession(['session_expired' => true])
        ->get(route('login'))
        ->assertOk()
        ->assertSee(__('Your session expired. Sign in again to continue.'));
});

test('login page hides session expired notice by default', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('Your session expired. Sign in again to continue.'));
});

test('login livewire component reads session expired flash on mount', function (): void {
    $this->withSession(['session_expired' => true]);

    Livewire::test(Login::class)
        ->assertSet('showSessionExpiredNotice', true);
});

test('guest page refresh redirects to login and flashes session expired notice', function (): void {
    $this->withHeader('Referer', url('/admin/users'))
        ->get(route('admin.users.index'))
        ->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('Your session expired. Sign in again to continue.'));
});

test('guest redirect without referer stays on plain login without notice', function (): void {
    $this->get(route('admin.users.index'))
        ->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee(__('Your session expired. Sign in again to continue.'));
});

test('unauthenticated pin toggle redirects to login and flashes session expired notice', function (): void {
    $this->postJson(route('pins.toggle'), [
        'label' => 'Test',
        'url' => '/test',
        'icon' => null,
    ])->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertSee(__('Your session expired. Sign in again to continue.'));
});
