<?php

use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    Process::fake();
});

it('renders the boot beacon on full app-layout loads', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('id="blb-boot-beacon"', false)
        ->assertSee('This page could not finish loading.');
});

it('omits the boot beacon on wire:navigate requests', function (): void {
    // Navigate requests keep the client's already-booted chrome (see
    // $skipShellRender in the app layout) — a beacon there would either
    // duplicate or false-alarm on a shell that is provably alive.
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'), [
        'X-Livewire-Navigate' => 'true',
    ]);

    $response->assertOk()
        ->assertDontSee('id="blb-boot-beacon"', false);
});

it('omits the boot beacon on guest auth pages', function (): void {
    // The beacon guards the app shell's Alpine-gated menu; the guest auth
    // layout renders no shell, so nothing removes the beacon there and it
    // would always false-alarm.
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('id="blb-boot-beacon"', false);
});
