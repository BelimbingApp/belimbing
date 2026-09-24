<?php

use App\Base\Database\PHPStan\GrowingTableUnboundedLoadRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<GrowingTableUnboundedLoadRule> */
class GrowingTableUnboundedLoadRuleTest extends RuleTestCase
{
    private function fixtureRoot(): string
    {
        return dirname(__DIR__, 3).'/Fixtures/growing-table';
    }

    protected function getRule(): Rule
    {
        return new GrowingTableUnboundedLoadRule($this->createReflectionProvider());
    }

    public function test_unbounded_loads_of_a_growing_table_are_refused_and_bounded_ones_pass(): void
    {
        $root = $this->fixtureRoot();
        $message = static fn (string $call, string $model = 'GrowingTableFixture\Models\RunLog'): string => sprintf(
            '%s on growing table model [%s] loads every row; limit/take/forPage it, paginate, chunk, lazy or cursor it, or suppress it inline with the reason it is bounded.',
            $call,
            $model,
        );

        $this->analyse([
            $root.'/app/Loads.php',
            $root.'/app/Models/RunLog.php',
            $root.'/app/Models/RunLogLine.php',
            $root.'/app/Models/Task.php',
        ], [
            [$message('::all()'), 14],
            [$message('->get()'), 15],
            [$message('->get()'), 16],
            [$message('->get()'), 17],
            [$message('->pluck()'), 18],
            [$message('->getModels()'), 19],
            [$message('->get()'), 20],
            [$message('->get()'), 21],
            [$message('->get()'), 22],
            [$message('->get()'), 23],
            [$message('->get()'), 24],
            [$message('->get()'), 25],
            [$message('->get()'), 26],
            [$message('->pluck()'), 27],
            [$message('->get()'), 28],
            [$message('->get()', 'GrowingTableFixture\Models\RunLogLine'), 29],
            [$message('->get()', 'GrowingTableFixture\Models\RunLogLine'), 30],
        ]);
    }
}
