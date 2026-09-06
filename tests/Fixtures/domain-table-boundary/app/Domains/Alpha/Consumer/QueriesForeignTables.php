<?php

namespace DomainTableBoundaryFixture\Alpha\Consumer;

use Illuminate\Support\Facades\DB;

function table(): void
{
    DB::table('beta_things')->count();
}

function select(): void
{
    DB::select('select * from beta_things');
}

function from(): void
{
    DB::query()->from('beta_things')->count();
}
