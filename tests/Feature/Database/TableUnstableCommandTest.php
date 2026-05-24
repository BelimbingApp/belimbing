<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('legacy table unstable command points developers to source-local incubating schema', function (): void {
    $this->artisan('blb:table:unstable', ['tables' => ['users']])
        ->expectsOutputToContain('Local table stability toggles are retired.')
        ->expectsOutputToContain('app/Modules/Core/User/Database/Migrations/0200_01_20_000000_create_users_table.php')
        ->expectsOutputToContain('use App\\Base\\Database\\Concerns\\IncubatingSchema;')
        ->expectsOutputToContain('php artisan migrate --dev')
        ->assertExitCode(0);
});
