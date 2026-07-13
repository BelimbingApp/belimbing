<?php

it('documents status bar diagnostics in the feedback reference', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.ui-reference.show', ['section' => 'feedback']));

    $response->assertOk()
        ->assertSee('Status Bar Diagnostics')
        ->assertSee('System diagnostics')
        ->assertSee('FrankenPHP worker reload needs attention')
        ->assertSee('Menu item hidden: Supplier Research')
        ->assertSee('aria-label="Close diagnostics"', false)
        ->assertSee('Open related page')
        ->assertDontSee('aria-label="Open related diagnostics"', false);
});

it('renders executable notification demo handlers', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.ui-reference.show', ['section' => 'feedback']));

    $response->assertOk()
        ->assertSee("@click=\"demo('success')\"", false)
        ->assertSee("@click=\"demo('warning')\"", false)
        ->assertSee("@click=\"demo('error')\"", false)
        ->assertDontSee('@click="demo(\'success\', @js(', false);
});

it('keeps the catalog in a mobile drawer so reference content stays primary', function (): void {
    $this->actingAs(createAdminUser());

    $response = $this->get(route('admin.system.ui-reference.show', ['section' => 'navigation']));

    $response->assertOk()
        ->assertSee('data-side-panel-mobile-trigger-shell', false)
        ->assertSee('sticky top-0', false)
        ->assertSee('data-side-panel-mobile-trigger', false)
        ->assertSee('data-side-panel-mobile', false)
        // The drawer's dismiss control is an x-ui.icon-action; its accessible
        // name is carried by the sr-only label + title, not a raw aria-label.
        ->assertSee('Close catalog')
        ->assertSee('Catalog: Navigation')
        ->assertSee('Catalog')
        ->assertDontSee('Catalog Pages')
        ->assertSee('aria-label="UI reference pages"', false);

    $this->get(route('admin.system.ui-reference.show', ['section' => 'inputs']))
        ->assertOk()
        ->assertSee('Catalog: Inputs');
});
