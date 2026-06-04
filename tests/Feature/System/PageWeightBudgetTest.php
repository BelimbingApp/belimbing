<?php

use Illuminate\Support\Facades\Artisan;

/**
 * Budget guardrail (Phase 6 of docs/plans/performance-page-rendering.md).
 *
 * Re-uses the blb:perf:page-weights harness to fail when a full-page Livewire
 * component renders more than the ~150 KB initial-HTML budget. It runs against
 * the empty test database, so data-driven weights are understated — but the
 * pages this protects (menu inspector, capabilities, AI provider catalog, …)
 * are driven by the menu registry / config / static catalogs, not table rows,
 * so they stay heavy here and their regressions are caught.
 *
 * This is a ratchet: $allow lists pages already known to exceed the budget,
 * each with a reason and a plan entry. Shrink it as pages are fixed; a NEW
 * page over budget fails this test.
 */
test('full-page Livewire components stay within the HTML budget', function (): void {
    // The harness logs in a user to render pages as; ensure one exists.
    $this->actingAs(createAdminUser());

    $allow = [
        // ~152 KB: aggregate x-ui overhead on the active-tab workbench (no single
        // hotspot; the create modal already includes only the active form). Marginal
        // overage on a higher-risk Commerce page. See plan Phase 4.
        'commerce/catalog',
    ];

    $exit = Artisan::call('blb:perf:page-weights', [
        '--max-kb' => 150,
        '--strict' => true,
        '--allow' => $allow,
        '--limit' => 1,
    ]);

    expect($exit)->toBe(0, Artisan::output());
});
