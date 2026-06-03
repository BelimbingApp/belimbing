<?php

/**
 * Phase 2 (docs/plans/performance-page-rendering.md): the sidebar is a persisted
 * island whose active highlight is computed client-side (a small "best match wins"
 * pass sets data-current on the active link) rather than by a per-request server
 * re-render. These tests pin the server-rendered contract the client behavior
 * depends on — navigable links + the data-current-driven styling hooks; the
 * runtime highlight/scroll behavior itself is verified in the browser. A full
 * HTTP GET is used so the app layout (which hosts the sidebar via its view
 * composer) actually renders.
 */
test('the app layout renders the persisted sidebar with data-current styling hooks', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    // Menu links are SPA-navigable, and active styling is driven entirely by a
    // client-set [data-current] attribute (no server-rendered active ternary).
    expect($html)->toContain('wire:navigate')
        // Leaf links style themselves from data-current; containers from a descendant's.
        ->and($html)->toContain('data-[current]:bg-surface-card')
        ->and($html)->toContain('group-has-[[data-current]]/menuitem:');
});

test('the sidebar no longer hard-codes server-computed active classes', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    // The old behavior baked `bg-surface-card text-ink font-medium` onto whichever
    // row PHP decided was active. Now that combination only appears as data-/has-
    // variants, never as a plain always-on class trio on a link row.
    expect($html)->not->toContain('rounded-none transition text-link bg-surface-card text-ink font-medium');
});
