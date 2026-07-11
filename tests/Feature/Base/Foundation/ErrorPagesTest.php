<?php

use Illuminate\Support\Facades\Route;

it('offers try again instead of a self-referential back to home when the error happened on home', function (): void {
    Route::get('/', fn () => abort(500))->middleware([]);

    $this->get('/')
        ->assertStatus(500)
        ->assertSee('Try again')
        ->assertDontSee('Back to home');
});

it('offers back to home normally when the error happened elsewhere', function (): void {
    Route::get('/elsewhere-broken', fn () => abort(500))->middleware([]);

    $this->get('/elsewhere-broken')
        ->assertStatus(500)
        ->assertSee('Back to home');
});

it('drops the session-expired secondary home link when already home', function (): void {
    Route::get('/', fn () => abort(419))->middleware([]);

    $html = $this->get('/')->assertStatus(419)->getContent();

    expect($html)->not->toContain('class="quiet"');
});
