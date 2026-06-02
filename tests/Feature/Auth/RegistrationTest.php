<?php

use Illuminate\Support\Facades\Route;

test('public self registration is not exposed', function (): void {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
});
