<?php

use App\Modules\Core\User\Livewire\Settings\DeleteUserForm;
use App\Modules\Core\User\Livewire\Settings\Profile;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

const PROFILE_TEST_USER_NAME = 'Test User';
const PROFILE_TEST_USER_EMAIL = 'test@example.com';

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', PROFILE_TEST_USER_NAME)
        ->set('email', PROFILE_TEST_USER_EMAIL)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual(PROFILE_TEST_USER_NAME);
    expect($user->email)->toEqual(PROFILE_TEST_USER_EMAIL);
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(Profile::class)
        ->set('name', PROFILE_TEST_USER_NAME)
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->set('confirmText', DeleteUserForm::CONFIRM_PHRASE)
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('account deletion is refused without the typed acknowledgment', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(DeleteUserForm::class)
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors('confirmText');

    expect($user->fresh())->not->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test(DeleteUserForm::class)
        ->set('password', 'wrong-password')
        ->set('confirmText', DeleteUserForm::CONFIRM_PHRASE)
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
