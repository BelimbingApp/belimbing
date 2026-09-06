<?php

use App\Base\FeatureFlags\FeatureFlagReadRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<FeatureFlagReadRule> */
class FeatureFlagReadRuleTest extends RuleTestCase
{
    private array $allowlist = [];

    protected function getRule(): Rule
    {
        return new FeatureFlagReadRule(dirname(__DIR__, 3).'/Fixtures/feature-flags', $this->allowlist);
    }

    public function test_foreign_flag_requires_its_declaring_module(): void
    {
        $this->analyse([dirname(__DIR__, 3).'/Fixtures/feature-flags/app/Core/Consumer/ReadFlags.php'], [
            ['Module [demo/consumer] reads flag [foreign.flag] without declaring it or directly requiring its declaring module.', 12],
        ]);
    }

    public function test_explicit_exception_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeatureFlagReadRule(__DIR__, ['demo/consumer' => ['foreign.flag' => ' ']]);
    }

    public function test_scoped_exception_allows_the_named_flag(): void
    {
        $this->allowlist = ['demo/consumer' => ['foreign.flag' => 'Reviewed compatibility bridge.']];
        $this->analyse([dirname(__DIR__, 3).'/Fixtures/feature-flags/app/Core/Consumer/ReadFlags.php'], []);
    }

    public function test_unknown_names_and_missing_manifests_fail_closed(): void
    {
        foreach (['Consumer/Dynamic.php', 'Unowned/ReadFlag.php'] as $file) {
            $this->analyse([dirname(__DIR__, 3).'/Fixtures/feature-flags/app/Core/'.$file], [
                ['Feature flag reads require an owning module manifest and a statically known flag name.', 9],
            ]);
        }
    }
}
