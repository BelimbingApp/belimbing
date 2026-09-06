<?php

use App\Base\Foundation\PHPStan\DomainEloquentModelBoundaryRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainEloquentModelBoundaryRule> */
class DomainEloquentModelBoundaryRuleTest extends RuleTestCase
{
    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 3).'/Fixtures/domain-eloquent-boundary';
    }

    protected function getRule(): Rule
    {
        return new DomainEloquentModelBoundaryRule(
            $this->fixtureRoot(),
            $this->createReflectionProvider(),
        );
    }

    public function test_foreign_domain_eloquent_model_typehint_is_refused(): void
    {
        $root = $this->fixtureRoot();
        $this->analyse([
            $root.'/app/Domains/Alpha/Consumer/UsesForeignModel.php',
            $root.'/app/Domains/Beta/Provider/Models/ForeignModel.php',
            $root.'/app/Domains/Beta/Provider/Contracts/ForeignContract.php',
        ], [
            [
                'Module [alpha/consumer] must not reference Eloquent model [DomainBoundaryFixture\Beta\Provider\Models\ForeignModel] owned by module [beta/provider]; use an exported contract instead.',
                9,
            ],
        ]);
    }

    public function test_exported_contract_typehint_is_allowed(): void
    {
        $root = $this->fixtureRoot();
        $this->analyse([
            $root.'/app/Domains/Alpha/Consumer/UsesForeignContract.php',
            $root.'/app/Domains/Beta/Provider/Contracts/ForeignContract.php',
        ], []);
    }

    public function test_extension_code_may_reference_domain_models(): void
    {
        $root = $this->fixtureRoot();
        $this->analyse([
            $root.'/app/Extensions/Demo/Addon/UsesDomainModel.php',
            $root.'/app/Domains/Beta/Provider/Models/ForeignModel.php',
            $root.'/app/Domains/Beta/Provider/Contracts/ForeignContract.php',
        ], []);
    }
}
