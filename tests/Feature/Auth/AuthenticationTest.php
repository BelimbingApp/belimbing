<?php

use App\Modules\Core\User\Livewire\Auth\Login;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('legacy bcrypt passwords are upgraded to argon2id after login', function () {
    $user = User::factory()->create();
    $legacyHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);

    DB::table('users')->where('id', $user->id)->update(['password' => $legacyHash]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $password = $user->refresh()->password;

    expect(password_get_info($password)['algoName'])->toBe('argon2id')
        ->and(Hash::check('password', $password))->toBeTrue();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login');

    $response->assertHasErrors('email');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
