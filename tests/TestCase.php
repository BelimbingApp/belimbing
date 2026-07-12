<?php

namespace Tests;

use App\Base\Foundation\Services\DomainState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed baseline reference data after each test database refresh.
     *
     * Uses a test-only seeder to avoid network-bound or dev-only seeders.
     */
    protected bool $seed = true;

    /**
     * Use deterministic test seeding (registry seeders for modules listed in tests manifest).
     *
     * @var class-string
     */
    protected string $seeder = TestingBaselineSeeder::class;

    /**
     * Tests must never inherit the developer's disabled-domains state, and
     * one test's toggles must never leak into the next test's app boot.
     */
    protected function setUp(): void
    {
        $statePath = sys_get_temp_dir().'/blb-test-disabled-domains-'.getmypid().'.json';

        if (is_file($statePath)) {
            unlink($statePath);
        }

        DomainState::useStatePath($statePath);

        parent::setUp();

        // #[Lazy] pages (Modules, Updates, GitHub Access) would render only
        // their skeleton placeholder under test, hiding the real component
        // from every assertion. Livewire clears the flag on flush-state,
        // which fires after every Livewire::test operation — so re-arm it on
        // each flush, keeping it in force for the whole test. Tests that
        // assert lazy behavior itself can flip it back per component.
        Livewire::withoutLazyLoading();
        \Livewire\on('flush-state', static fn () => Livewire::withoutLazyLoading());
    }
}
