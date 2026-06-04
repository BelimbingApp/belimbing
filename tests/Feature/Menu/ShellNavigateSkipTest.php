<?php

/**
 * On wire:navigate the persisted chrome (sidebar + bars) is kept client-side and
 * the freshly-rendered copy is discarded, so the server skips rendering it for
 * requests carrying the X-Livewire-Navigate header — cutting ~982 KB of sidebar
 * HTML (and its render) off every navigation. Full page loads still render it.
 */
test('a full page load renders the sidebar chrome', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Main navigation'); // the sidebar <nav> aria-label
});

test('a wire:navigate request skips the persisted sidebar chrome', function (): void {
    $user = createAdminUser();

    $full = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
    $navigate = $this->actingAs($user)
        ->withHeaders(['X-Livewire-Navigate' => '1'])
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    // Sidebar is omitted on navigate (client keeps its persisted copy)...
    expect($navigate)->not->toContain('Main navigation')
        // ...the @persist markers are still present so the morph preserves the chrome...
        ->and($navigate)->toContain('sidebar-desktop')
        // ...and the navigate payload is dramatically smaller than the full page.
        ->and(strlen($navigate))->toBeLessThan((int) (strlen($full) * 0.6));
});
