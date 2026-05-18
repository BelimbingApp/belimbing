<?php

namespace App\Base\Database\Console\Concerns;

trait PrintsTableUnstableUsage
{
    protected function printTableUnstableUsage(string $introLine): void
    {
        $this->line($introLine);
        $this->line('');
        $this->line('    <comment>php artisan blb:table:unstable table_name</comment>     Mark one table');
        $this->line('    <comment>php artisan blb:table:unstable table_a table_b</comment> Mark multiple tables');
        $this->line('    <comment>php artisan blb:table:unstable ai_*</comment>           Prefix wildcard');
        $this->line('    <comment>php artisan blb:table:unstable people_*_entitlement_*</comment> Multi-part wildcard');
        $this->line('');
    }
}
