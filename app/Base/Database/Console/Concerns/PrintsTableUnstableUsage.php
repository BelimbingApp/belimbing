<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Concerns;

trait PrintsTableUnstableUsage
{
    protected function printTableUnstableUsage(string $introLine): void
    {
        $this->line($introLine);
        $this->line('');
        $this->line('    <comment>php artisan blb:table:unstable table_name</comment>     Mark one table');
        $this->line('    <comment>php artisan blb:table:unstable table_a table_b</comment> Mark multiple tables');
        $this->line('    <comment>php artisan blb:table:unstable ai_*</comment>           Trailing wildcard (prefix match)');
        $this->line('');
    }
}
