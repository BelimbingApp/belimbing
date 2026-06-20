<?php

use App\Modules\Core\User\Livewire\Settings\Password;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Password::class)
        ->set('currentPassword', 'password')
        ->set('password', 'new-password')
        ->set('passwordConfirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();
    $response->assertSee(__('Password updated successfully.'));

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test(Password::class)
        ->set('currentPassword', 'wrong-password')
        ->set('password', 'new-password')
        ->set('passwordConfirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['currentPassword']);
    $response->assertSee(__('Password was not saved. Review the highlighted fields.'));
});
