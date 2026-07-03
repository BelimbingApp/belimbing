<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Modules;
use App\Base\Foundation\Services\ExtensionInstaller;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const EXTENSION_INSTALL_FOLDER = 'zzkiat';
const EXTENSION_INSTALL_REPO = 'https://github.com/zzowner/blb-zzkiat';
const EXTENSION_INSTALL_OWNER = 'zzowner';
const EXTENSION_INSTALL_TOKEN = 'ghp_testtoken1234567890abcdef';
const EXTENSION_INSTALL_TABLE = 'zzkiat_table';
const EXTENSION_INSTALL_SETTING = 'zzkiat.option';
const EXTENSION_INSTALL_MIGRATION = '2099_01_01_000000_create_zzkiat_table_table';
const EXTENSION_INSTALL_RELOAD_SCHEDULED = 'Domain runtime reload scheduled in the background.';
const EXTENSION_INSTALL_BASE_PATH = 'extensions/';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
    config(['extensions.catalog' => [
        EXTENSION_INSTALL_FOLDER => ['repo' => EXTENSION_INSTALL_REPO, 'description' => 'Test extension.'],
    ]]);
});

afterEach(function (): void {
    File::deleteDirectory(base_path(EXTENSION_INSTALL_BASE_PATH.EXTENSION_INSTALL_FOLDER));
});

function createExtensionInstallFakeCheckout(): string
{
    $base = base_path(EXTENSION_INSTALL_BASE_PATH.EXTENSION_INSTALL_FOLDER);
    $module = $base.'/Sample';

    File::ensureDirectoryExists($module.'/Database/Migrations');
    File::ensureDirectoryExists($module.'/Config');

    file_put_contents(
        $module.'/ServiceProvider.php',
        <<<'PHP'
        <?php

        namespace Extensions\Zzkiat\Sample;

        use Illuminate\Support\ServiceProvider as BaseServiceProvider;

        class ServiceProvider extends BaseServiceProvider {}
        PHP,
    );

    file_put_contents(
        $module.'/Database/Migrations/'.EXTENSION_INSTALL_MIGRATION.'.php',
        <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('zzkiat_table', function (Blueprint $table): void {
                    $table->id();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('zzkiat_table');
            }
        };
        PHP,
    );

    file_put_contents(
        $module.'/Config/settings.php',
        <<<'PHP'
        <?php
        return [
            'editable' => [
                'zzkiat' => [
                    'fields' => [
                        ['key' => 'zzkiat.option', 'label' => 'Option', 'type' => 'text'],
                    ],
                ],
            ],
        ];
        PHP,
    );

    return $base;
}

it('marks a catalog extension token-ready when a token is stored for its owner', function (): void {
    app(SettingsService::class)->set('integrations.github.token.'.EXTENSION_INSTALL_OWNER, EXTENSION_INSTALL_TOKEN, encrypted: true);

    $available = app(ExtensionInstaller::class)->available();

    expect($available)->toHaveKey(EXTENSION_INSTALL_FOLDER)
        ->and($available[EXTENSION_INSTALL_FOLDER]['owner'])->toBe(EXTENSION_INSTALL_OWNER)
        ->and($available[EXTENSION_INSTALL_FOLDER]['has_token'])->toBeTrue();
});

it('lists available extensions on the Available tab', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Modules::class, ['tab' => 'available'])
        ->assertSee('Available extensions')
        ->assertSee(EXTENSION_INSTALL_FOLDER);
});

it('clones an extension with the stored github token and redirects', function (): void {
    app(SettingsService::class)->set('integrations.github.token.'.EXTENSION_INSTALL_OWNER, EXTENSION_INSTALL_TOKEN, encrypted: true);
    $this->actingAs(createAdminUser());
    Process::fake();

    Livewire::test(Modules::class)
        ->call('installExtension', EXTENSION_INSTALL_FOLDER)
        ->assertRedirect(route('admin.system.software.modules.index'));

    $expectedAuthHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.EXTENSION_INSTALL_TOKEN);

    Process::assertRan(fn ($process): bool => in_array('clone', $process->command, true)
        && in_array(EXTENSION_INSTALL_REPO, $process->command, true)
        && in_array(base_path(EXTENSION_INSTALL_BASE_PATH.EXTENSION_INSTALL_FOLDER), $process->command, true)
        && in_array($expectedAuthHeader, $process->command, true));
});

it('blocks extension install for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());
    Process::fake();

    Livewire::test(Modules::class)->call('installExtension', EXTENSION_INSTALL_FOLDER)->assertForbidden();

    Process::assertDidntRun(fn ($process): bool => in_array('clone', $process->command, true));
});

it('uninstalls an extension while keeping database state', function (): void {
    createExtensionInstallFakeCheckout();
    Schema::create(EXTENSION_INSTALL_TABLE, fn ($table) => $table->id());
    DB::table('migrations')->insert(['migration' => EXTENSION_INSTALL_MIGRATION, 'batch' => 999]);
    Setting::query()->create(['key' => EXTENSION_INSTALL_SETTING, 'value' => 'kept', 'scope_type' => null, 'scope_id' => null]);

    $result = app(ExtensionInstaller::class)->uninstall(EXTENSION_INSTALL_FOLDER, dropTables: false);

    expect(is_dir(base_path(EXTENSION_INSTALL_BASE_PATH.EXTENSION_INSTALL_FOLDER)))->toBeFalse()
        ->and($result['droppedTables'])->toBe([])
        ->and($result['reloadLog'])->toContain(EXTENSION_INSTALL_RELOAD_SCHEDULED)
        ->and(Schema::hasTable(EXTENSION_INSTALL_TABLE))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', EXTENSION_INSTALL_MIGRATION)->exists())->toBeTrue()
        ->and(Setting::query()->where('key', EXTENSION_INSTALL_SETTING)->exists())->toBeTrue();
});

it('uninstalls an extension and drops the state it claimed', function (): void {
    createExtensionInstallFakeCheckout();
    Schema::create(EXTENSION_INSTALL_TABLE, fn ($table) => $table->id());
    DB::table('migrations')->insert(['migration' => EXTENSION_INSTALL_MIGRATION, 'batch' => 999]);
    Setting::query()->create(['key' => EXTENSION_INSTALL_SETTING, 'value' => 'gone', 'scope_type' => null, 'scope_id' => null]);

    $result = app(ExtensionInstaller::class)->uninstall(EXTENSION_INSTALL_FOLDER, dropTables: true);

    expect($result['droppedTables'])->toBe([EXTENSION_INSTALL_TABLE])
        ->and($result['prunedLedger'])->toBe(1)
        ->and($result['deletedSettings'])->toBe(1)
        ->and(Schema::hasTable(EXTENSION_INSTALL_TABLE))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', EXTENSION_INSTALL_MIGRATION)->exists())->toBeFalse()
        ->and(Setting::query()->where('key', EXTENSION_INSTALL_SETTING)->exists())->toBeFalse();
});
