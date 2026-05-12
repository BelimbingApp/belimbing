<?php

it('links the inactive Lara status-bar action to AI Providers with setup guidance', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('href="'.route('admin.ai.providers').'"', false)
        ->assertSee('title="Activate Lara"', false)
        ->assertSee('Activate Lara');
});
