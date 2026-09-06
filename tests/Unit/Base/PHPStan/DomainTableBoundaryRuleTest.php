<?php

use App\Base\Foundation\PHPStan\DomainTableBoundaryRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainTableBoundaryRule> */
class DomainTableBoundaryRuleTest extends RuleTestCase
{
    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 3).'/Fixtures/domain-table-boundary';
    }

    protected function getRule(): Rule
    {
        return new DomainTableBoundaryRule($this->fixtureRoot());
    }

    public function test_foreign_domain_table_queries_are_refused_for_each_supported_query_shape(): void
    {
        $root = $this->fixtureRoot();
        $message = 'Module [alpha/consumer] must not query table [beta_things] owned by module [beta/provider]; the owner must export it through extra.blb.shared-tables and the consumer must directly require the owner.';

        $this->analyse([$root.'/app/Domains/Alpha/Consumer/QueriesForeignTables.php'], [
            [$message, 9],
            [$message, 14],
            [$message, 19],
        ]);
    }

    public function test_owner_and_declared_direct_consumer_may_query_the_table(): void
    {
        $root = $this->fixtureRoot();

        $this->analyse([
            $root.'/app/Domains/Beta/Provider/Database/Migrations/3000_01_01_000000_create_beta_things_table.php',
            $root.'/app/Domains/Alpha/Partner/QueriesSharedTable.php',
        ], []);
    }
}
