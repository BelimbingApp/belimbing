<?php

use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\DomainState;
use App\Base\Settings\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

afterEach(function (): void {
    File::deleteDirectory(app_path('Modules/ZzInstallable'));
});

function domainInstaller(): DomainInstaller
{
    return app(DomainInstaller::class);
}

it('lists catalog entries without a checkout as available', function (): void {
    config(['domains.catalog' => [
        'ZzInstallable' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture domain.'],
    ]]);

    expect(domainInstaller()->available())->toHaveKey('ZzInstallable');

    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    expect(domainInstaller()->available())->toBe([]);
});

it('installs by cloning the catalog repo and migrating in a subprocess', function (): void {
    config(['domains.catalog' => [
        'ZzInstallable' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture domain.'],
    ]]);

    Process::fake();
    DomainState::disable('ZzInstallable');

    $result = domainInstaller()->install('ZzInstallable');

    expect($result['ok'])->toBeTrue();

    Process::assertRan(fn ($process): bool => $process->command === ['git', 'clone', 'https://example.test/zz.git', app_path('Modules/ZzInstallable')]);
    Process::assertRan(fn ($process): bool => $process->command === [PHP_BINARY, 'artisan', 'migrate', '--force']);

    // A stale disabled flag from a previous uninstall must not mute the new checkout.
    expect(DomainState::isDisabled('ZzInstallable'))->toBeFalse();
});

it('rejects installing unknown or already-installed domains', function (): void {
    config(['domains.catalog' => [
        'ZzInstallable' => ['repo' => 'https://example.test/zz.git', 'description' => 'Fixture domain.'],
    ]]);

    expect(fn () => domainInstaller()->install('ZzNotInCatalog'))
        ->toThrow(InvalidArgumentException::class, 'not in the catalog');

    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    expect(fn () => domainInstaller()->install('ZzInstallable'))
        ->toThrow(InvalidArgumentException::class, 'already installed');
});

it('reports installed domains with module list, disabled flag, and git state', function (): void {
    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option', ['withGit' => true]);

    DomainState::disable('ZzInstallable');

    $installed = collect(domainInstaller()->installed())->firstWhere('name', 'ZzInstallable');

    expect($installed)->not->toBeNull()
        ->and($installed['modules'])->toBe([])
        ->and($installed['disabled'])->toBeTrue()
        ->and($installed['git']['hasGit'])->toBeTrue()
        ->and($installed['git']['dirty'])->toBeTrue();
});

it('uninstall deletes the checkout but keeps database state by default', function (): void {
    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    Schema::create('zz_install_table', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => '2099_01_01_000000_create_zz_install_table_table',
        'batch' => 999,
    ]);
    Setting::query()->create(['key' => 'zz_install.option', 'value' => 'kept', 'scope_type' => null, 'scope_id' => null]);

    $result = domainInstaller()->uninstall('ZzInstallable', dropTables: false);

    expect(is_dir(app_path('Modules/ZzInstallable')))->toBeFalse()
        ->and($result['droppedTables'])->toBe([])
        ->and(Schema::hasTable('zz_install_table'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', '2099_01_01_000000_create_zz_install_table_table')->exists())->toBeTrue()
        ->and(Setting::query()->where('key', 'zz_install.option')->exists())->toBeTrue();
});

it('uninstall with drop removes the tables, ledger rows, and settings the domain claimed', function (): void {
    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    Schema::create('zz_install_table', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => '2099_01_01_000000_create_zz_install_table_table',
        'batch' => 999,
    ]);
    Setting::query()->create(['key' => 'zz_install.option', 'value' => 'gone', 'scope_type' => null, 'scope_id' => null]);

    $result = domainInstaller()->uninstall('ZzInstallable', dropTables: true);

    expect($result['droppedTables'])->toBe(['zz_install_table'])
        ->and($result['prunedLedger'])->toBe(1)
        ->and($result['deletedSettings'])->toBe(1)
        ->and(Schema::hasTable('zz_install_table'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2099_01_01_000000_create_zz_install_table_table')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'zz_install.option')->exists())->toBeFalse();
});

it('uninstall with drop never touches a table another module still claims', function (): void {
    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    // The fixture domain also (wrongly) declares the framework's users table.
    file_put_contents(
        app_path('Modules/ZzInstallable/Sample/Database/Migrations/2099_01_01_000001_create_users_table.php'),
        "<?php\n// Schema::create('users', ...) — claim collides with Core.\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration {\n    public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create('users', fn (\$t) => \$t->id()); }\n};",
    );

    $result = domainInstaller()->uninstall('ZzInstallable', dropTables: true);

    expect($result['droppedTables'])->not->toContain('users')
        ->and(Schema::hasTable('users'))->toBeTrue();
});

it('refuses to uninstall Core or a domain that is not installed', function (): void {
    expect(fn () => domainInstaller()->uninstall('Core', dropTables: false))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => domainInstaller()->uninstall('ZzMissing', dropTables: false))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => domainInstaller()->uninstall('../etc', dropTables: false))
        ->toThrow(InvalidArgumentException::class);
});

it('clears the disabled flag when uninstalling a disabled domain', function (): void {
    createFakeDomainCheckout('ZzInstallable', 'zz_install_table', 'zz_install.option');

    domainInstaller()->disable('ZzInstallable');
    expect(DomainState::isDisabled('ZzInstallable'))->toBeTrue();

    domainInstaller()->uninstall('ZzInstallable', dropTables: false);

    expect(DomainState::isDisabled('ZzInstallable'))->toBeFalse();
});
