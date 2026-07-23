<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('moves supported preference values to user settings without replacing existing overrides', function (): void {
    Schema::table('users', function (Blueprint $table): void {
        $table->json('prefs')->nullable();
    });

    $user = User::factory()->create();
    DB::table('users')->where('id', $user->id)->update([
        'prefs' => json_encode([
            'landing_menu_id' => 'admin.system.settings',
            'dashboard' => ['weather', 'tasks'],
            'last_used_model' => ['1' => ['provider' => 'openai', 'model' => 'gpt-5']],
            'theme' => 'dark',
            'unrelated' => 'leave-behind',
        ], JSON_THROW_ON_ERROR),
    ]);

    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());
    app(SettingsService::class)->set('ui.theme', 'light', $scope);

    $migration = require app_path('Modules/Core/User/Database/Migrations/0200_01_20_000007_migrate_user_preferences_to_settings.php');
    $migration->up();

    expect(Schema::hasColumn('users', 'prefs'))->toBeFalse()
        ->and(app(SettingsService::class)->get('ui.landing_menu_id', $scope))->toBe('admin.system.settings')
        ->and(app(SettingsService::class)->get('ui.dashboard.layout', $scope))->toBe(['weather', 'tasks'])
        ->and(app(SettingsService::class)->get('ai.last_used_model_hints', $scope))
        ->toBe(['1' => ['provider' => 'openai', 'model' => 'gpt-5']])
        ->and(app(SettingsService::class)->get('ui.theme', $scope))->toBe('light');
});
