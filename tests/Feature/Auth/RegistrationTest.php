<?php

use App\Modules\Core\User\Livewire\Auth\Register;
use App\Modules\Core\User\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('new users can register', function () {
    Event::fake([Registered::class]);

    $response = Livewire::test(Register::class)
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('passwordConfirmation', 'password')
        ->call('register');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $user = User::query()->where('email', 'test@example.com')->first();

    expect($user)
        ->not()->toBeNull()
        ->and($user->name)->toBe('Test User')
        ->and(Hash::check('password', $user->password))->toBeTrue();

    Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($user));
    $this->assertAuthenticated();
});
