<?php

it('documents status bar diagnostics in the feedback reference', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.ui-reference.show', ['section' => 'feedback']));

    $response->assertOk()
        ->assertSee('Status Bar Diagnostics')
        ->assertSee('System diagnostics')
        ->assertSee('FrankenPHP worker reload needs attention')
        ->assertSee('Menu item hidden: Supplier Research')
        ->assertSee('Open Menu Inspector');
});
