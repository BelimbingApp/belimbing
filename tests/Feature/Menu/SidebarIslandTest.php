<?php

/**
 * Phase 2 (docs/plans/performance-page-rendering.md): the sidebar is a persisted
 * island whose active highlight is driven client-side by wire:current rather than
 * a per-request server re-render. These tests pin the server-rendered contract
 * (the directives/markup the client behavior depends on); the runtime behavior
 * itself is verified in the browser. A full HTTP GET is used so the app layout —
 * which hosts the sidebar via its view composer — actually renders.
 */
test('the app layout renders the persisted sidebar with wire:current active state', function (): void {
    $html = $this->actingAs(createAdminUser())
        ->get(route('profile.edit'))
        ->assertOk()
        ->getContent();

    // Active state is driven by wire:current (sets data-current), not a server ternary.
    expect($html)->toContain('wire:current')
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
    // row PHP decided was active. With wire:current that combination only appears
    // as data-/has- variants, never as a plain always-on class trio on a link row.
    expect($html)->not->toContain('rounded-none transition text-link bg-surface-card text-ink font-medium');
});
