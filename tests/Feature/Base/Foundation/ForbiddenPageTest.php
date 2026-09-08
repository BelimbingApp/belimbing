<?php

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/forbidden-fixture', fn () => abort(403, 'SECRET capability admin.private.record.17'))
        ->middleware('web');
});

it('renders a signed-in refusal once in the shared shell without sensitive details', function (): void {
    $html = $this->actingAs(createAdminUser())->get('/forbidden-fixture')
        ->assertForbidden()
        ->assertSee(__('Access restricted'))
        ->assertSee(__('Toggle sidebar'))
        ->assertDontSee('SECRET')
        ->assertDontSee('admin.private.record.17')
        ->assertDontSee(__('Pin to sidebar'))
        ->getContent();
    expect(substr_count($html, '<!DOCTYPE html>'))->toBe(1);
});

it('gives guests a standalone refusal with a safe home link', function (): void {
    $this->get('/forbidden-fixture')->assertForbidden()
        ->assertSee(__('Access restricted'))
        ->assertSee(__('Back to home'))
        ->assertDontSee(__('Toggle sidebar'))
        ->assertDontSee('SECRET');
});
