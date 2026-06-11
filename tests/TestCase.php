<?php

namespace Tests;

use App\Base\Foundation\Services\DomainState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
