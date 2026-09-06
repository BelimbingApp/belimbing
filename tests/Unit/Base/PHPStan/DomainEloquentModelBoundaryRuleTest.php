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

    public function test_nullable_union_and_return_foreign_models_are_refused_while_same_domain_and_non_models_pass(): void
    {
        $root = $this->fixtureRoot();
        $foreign = 'DomainBoundaryFixture\Beta\Provider\Models\ForeignModel';
        $message = 'Module [alpha/consumer] must not reference Eloquent model ['.$foreign.'] owned by module [beta/provider]; use an exported contract instead.';
        $this->analyse([
            $root.'/app/Domains/Alpha/Consumer/UsesNullableUnionAndReturn.php',
            $root.'/app/Domains/Alpha/Sibling/Models/LocalModel.php',
            $root.'/app/Domains/Beta/Provider/Models/ForeignModel.php',
            $root.'/app/Domains/Beta/Provider/Contracts/ForeignContract.php',
            $root.'/app/Domains/Beta/Provider/Services/NotAModel.php',
        ], [
            [$message, 26],
            [$message, 28],
            [$message, 30],
        ]);
    }

    public function test_manifest_fallback_names_modules_from_path(): void
    {
        $root = $this->fixtureRoot();
        $this->analyse([
            $root.'/app/Domains/Alpha/Consumer/UsesBareForeignModel.php',
            $root.'/app/Domains/Gamma/Bare/Models/BareModel.php',
        ], [
            [
                'Module [alpha/consumer] must not reference Eloquent model [DomainBoundaryFixture\Gamma\Bare\Models\BareModel] owned by module [gamma/bare]; use an exported contract instead.',
                9,
            ],
        ]);
    }
}
