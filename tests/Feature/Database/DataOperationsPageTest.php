<?php

use App\Base\Database\Livewire\DataOperations\Index;
use App\Base\Database\Models\DataOperationRun;
use App\Base\Database\Models\DataOperationTableSummary;
use Livewire\Livewire;

function makeOperationRun(array $attributes = []): DataOperationRun
{
    return DataOperationRun::query()->create(array_merge([
        'operation_type' => 'ax_import',
        'source' => 'sbg.ibp.market-spot',
        'actor_type' => 'console',
        'status' => 'succeeded',
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 38000,
        'table_count' => 1,
        'total_rows_affected' => 1542,
    ], $attributes));
}

it('lists recorded operations across mirror and imports', function () {
    $mirror = makeOperationRun(['operation_type' => 'mirror_push', 'source' => 'data-share.mirror', 'remote_instance_id' => 'supabase-prod']);
    $import = makeOperationRun();

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('#'.$import->id)
        ->assertSee('#'.$mirror->id)
        ->assertSee('ax_import')
        ->assertSee('mirror_push');
});

it('filters to just imports', function () {
    makeOperationRun(['operation_type' => 'mirror_push', 'source' => 'data-share.mirror']);
    makeOperationRun(['operation_type' => 'ax_import']);

    Livewire::test(Index::class)
        ->set('type', 'import')
        ->assertSee('ax_import')
        ->assertDontSee('mirror_push');
});

it('deep-links to a specific run and expands it on load', function () {
    $run = makeOperationRun();
    DataOperationTableSummary::query()->create([
        'run_id' => $run->id,
        'table_name' => 'sbg_ibp_market_spot_quotes',
        'actions' => ['upsert'],
        'rows_written' => 3,
    ]);

    Livewire::test(Index::class, ['run' => $run->id])
        ->assertSet('selectedRunId', $run->id)
        ->assertSee('sbg_ibp_market_spot_quotes');
});

it('expands a run to reveal per-table effect counts and range', function () {
    $run = makeOperationRun();
    DataOperationTableSummary::query()->create([
        'run_id' => $run->id,
        'table_name' => 'sbg_ibp_market_spot_quotes',
        'actions' => ['upsert', 'delete'],
        'rows_written' => 1500,
        'rows_deleted' => 42,
        'rows_rejected' => 7,
        'key_columns' => ['quoted_on'],
        'range_kind' => 'min_max_hint',
        'first_key' => '100432',
        'last_key' => '100987',
    ]);

    Livewire::test(Index::class)
        ->call('toggle', $run->id)
        ->assertSee('sbg_ibp_market_spot_quotes')
        ->assertSee('1,500 written')
        ->assertSee('42 del')
        ->assertSee('7 rej')
        ->assertSee('100432');
});
