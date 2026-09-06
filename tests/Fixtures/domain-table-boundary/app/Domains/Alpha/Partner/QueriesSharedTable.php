<?php

namespace DomainTableBoundaryFixture\Alpha\Partner;

use Illuminate\Support\Facades\DB;

function query(): void
{
    DB::table('beta_things')->count();
}
