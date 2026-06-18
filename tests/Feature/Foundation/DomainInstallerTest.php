<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\DomainState;
use App\Base\Settings\Models\Setting;
use App\Base\Support\PhpCli;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeDomainRuntimeReloader;

const DOMAIN_INSTALLER_FIXTURE_DOMAIN = 'ZzInstallable';
const DOMAIN_INSTALLER_FIXTURE_PATH = 'Modules/ZzInstallable';
const DOMAIN_INSTALLER_FIXTURE_REPO = 'https://example.test/zz.git';
const DOMAIN_INSTALLER_FIXTURE_DESCRIPTION = 'Fixture domain.';
const DOMAIN_INSTALLER_FIXTURE_MIGRATION = '2099_01_01_000000_create_zz_install_table_table';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
});

afterEach(function (): void {
    File::deleteDirectory(app_path(DOMAIN_INSTALLER_FIXTURE_PATH));
});

function domainInstaller(): DomainInstaller
{
    return app(DomainInstaller::class);
}

function domainInstallerRuntimeReloader(): FakeDomainRuntimeReloader
{
    return app(DomainRuntimeReloader::class);
}

function flushDomainInstallerAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

it('lists catalog entries without a checkout as available', function (): void {
    config(['domains.catalog' => [
        DOMAIN_INSTALLER_FIXTURE_DOMAIN => ['repo' => DOMAIN_INSTALLER_FIXTURE_REPO, 'description' => DOMAIN_INSTALLER_FIXTURE_DESCRIPTION],
    ]]);

    expect(domainInstaller()->available())->toHaveKey(DOMAIN_INSTALLER_FIXTURE_DOMAIN);

    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    expect(domainInstaller()->available())->toBe([]);
});

it('installs by cloning the catalog repo and migrating in a subprocess', function (): void {
    config(['domains.catalog' => [
        DOMAIN_INSTALLER_FIXTURE_DOMAIN => ['repo' => DOMAIN_INSTALLER_FIXTURE_REPO, 'description' => DOMAIN_INSTALLER_FIXTURE_DESCRIPTION],
    ]]);

    Process::fake();
    DomainState::disable(DOMAIN_INSTALLER_FIXTURE_DOMAIN);

    $result = domainInstaller()->install(DOMAIN_INSTALLER_FIXTURE_DOMAIN);

    expect($result['ok'])->toBeTrue();
    expect($result['log'])->toContain('Domain runtime reload scheduled in the background.')
        ->and(domainInstallerRuntimeReloader()->calls)->toBe(1);

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAIN_INSTALLER_FIXTURE_REPO, app_path(DOMAIN_INSTALLER_FIXTURE_PATH)]);
    Process::assertRan(fn ($process): bool => $process->command === PhpCli::current()->artisan(['migrate', '--force']));

    // A stale disabled flag from a previous uninstall must not mute the new checkout.
    expect(DomainState::isDisabled(DOMAIN_INSTALLER_FIXTURE_DOMAIN))->toBeFalse();
});

it('records retained audit actions for domain install and uninstall', function (): void {
    config(['domains.catalog' => [
        DOMAIN_INSTALLER_FIXTURE_DOMAIN => ['repo' => DOMAIN_INSTALLER_FIXTURE_REPO, 'description' => DOMAIN_INSTALLER_FIXTURE_DESCRIPTION],
    ]]);

    Process::fake();

    domainInstaller()->install(DOMAIN_INSTALLER_FIXTURE_DOMAIN);
    flushDomainInstallerAuditBuffer();

    $install = AuditAction::query()->where('event', 'domain.install')->firstOrFail();

    expect($install->is_retained)->toBeTrue()
        ->and($install->payload['domain'])->toBe(DOMAIN_INSTALLER_FIXTURE_DOMAIN)
        ->and($install->payload['status'])->toBe('succeeded')
        ->and($install->payload['repo'])->toBe(DOMAIN_INSTALLER_FIXTURE_REPO);

    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    domainInstaller()->uninstall(DOMAIN_INSTALLER_FIXTURE_DOMAIN, dropTables: false);
    flushDomainInstallerAuditBuffer();

    $uninstall = AuditAction::query()->where('event', 'domain.uninstall')->firstOrFail();

    expect($uninstall->is_retained)->toBeTrue()
        ->and($uninstall->payload['domain'])->toBe(DOMAIN_INSTALLER_FIXTURE_DOMAIN)
        ->and($uninstall->payload['status'])->toBe('succeeded')
        ->and($uninstall->payload['drop_tables'])->toBeFalse();
});

it('records and reloads domain enable and disable actions', function (): void {
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    $disableLog = domainInstaller()->disable(DOMAIN_INSTALLER_FIXTURE_DOMAIN);
    expect($disableLog)->toContain('Domain runtime reload scheduled in the background.')
        ->and(domainInstallerRuntimeReloader()->calls)->toBe(1);

    $enableLog = domainInstaller()->enable(DOMAIN_INSTALLER_FIXTURE_DOMAIN);
    expect($enableLog)->toContain('Domain runtime reload scheduled in the background.')
        ->and(domainInstallerRuntimeReloader()->calls)->toBe(2);

    $actions = AuditAction::query()
        ->whereIn('event', ['domain.disable', 'domain.enable'])
        ->orderBy('event')
        ->get()
        ->keyBy('event');

    expect($actions)->toHaveKeys(['domain.disable', 'domain.enable'])
        ->and($actions['domain.disable']->is_retained)->toBeTrue()
        ->and($actions['domain.enable']->is_retained)->toBeTrue();
});

it('rejects installing unknown or already-installed domains', function (): void {
    config(['domains.catalog' => [
        DOMAIN_INSTALLER_FIXTURE_DOMAIN => ['repo' => DOMAIN_INSTALLER_FIXTURE_REPO, 'description' => DOMAIN_INSTALLER_FIXTURE_DESCRIPTION],
    ]]);

    expect(fn () => domainInstaller()->install('ZzNotInCatalog'))
        ->toThrow(InvalidArgumentException::class, 'not in the catalog');

    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    expect(fn () => domainInstaller()->install(DOMAIN_INSTALLER_FIXTURE_DOMAIN))
        ->toThrow(InvalidArgumentException::class, 'already installed');
});

it('reports installed domains with module list, disabled flag, and git state', function (): void {
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option', ['withGit' => true]);

    DomainState::disable(DOMAIN_INSTALLER_FIXTURE_DOMAIN);

    $installed = collect(domainInstaller()->installed())->firstWhere('name', DOMAIN_INSTALLER_FIXTURE_DOMAIN);

    expect($installed)->not->toBeNull()
        ->and($installed['modules'])->toBe([])
        ->and($installed['disabled'])->toBeTrue()
        ->and($installed['git']['hasGit'])->toBeTrue()
        ->and($installed['git']['dirty'])->toBeTrue();
});

it('uninstall deletes the checkout but keeps database state by default', function (): void {
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    Schema::create('zz_install_table', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => DOMAIN_INSTALLER_FIXTURE_MIGRATION,
        'batch' => 999,
    ]);
    Setting::query()->create(['key' => 'zz_install.option', 'value' => 'kept', 'scope_type' => null, 'scope_id' => null]);

    $result = domainInstaller()->uninstall(DOMAIN_INSTALLER_FIXTURE_DOMAIN, dropTables: false);

    expect(is_dir(app_path(DOMAIN_INSTALLER_FIXTURE_PATH)))->toBeFalse()
        ->and($result['droppedTables'])->toBe([])
        ->and($result['reloadLog'])->toContain('Domain runtime reload scheduled in the background.')
        ->and(Schema::hasTable('zz_install_table'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', DOMAIN_INSTALLER_FIXTURE_MIGRATION)->exists())->toBeTrue()
        ->and(Setting::query()->where('key', 'zz_install.option')->exists())->toBeTrue();
});

it('uninstall with drop removes the tables, ledger rows, and settings the domain claimed', function (): void {
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    Schema::create('zz_install_table', fn ($table) => $table->id());
    DB::table('migrations')->insert([
        'migration' => DOMAIN_INSTALLER_FIXTURE_MIGRATION,
        'batch' => 999,
    ]);
    Setting::query()->create(['key' => 'zz_install.option', 'value' => 'gone', 'scope_type' => null, 'scope_id' => null]);

    $result = domainInstaller()->uninstall(DOMAIN_INSTALLER_FIXTURE_DOMAIN, dropTables: true);

    expect($result['droppedTables'])->toBe(['zz_install_table'])
        ->and($result['prunedLedger'])->toBe(1)
        ->and($result['deletedSettings'])->toBe(1)
        ->and(Schema::hasTable('zz_install_table'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', DOMAIN_INSTALLER_FIXTURE_MIGRATION)->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'zz_install.option')->exists())->toBeFalse();
});

it('uninstall with drop never touches a table another module still claims', function (): void {
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    // The fixture domain also (wrongly) declares the framework's users table.
    file_put_contents(
        app_path(DOMAIN_INSTALLER_FIXTURE_PATH.'/Sample/Database/Migrations/2099_01_01_000001_create_users_table.php'),
        "<?php\n// Schema::create('users', ...) — claim collides with Core.\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration {\n    public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create('users', fn (\$t) => \$t->id()); }\n};",
    );

    $result = domainInstaller()->uninstall(DOMAIN_INSTALLER_FIXTURE_DOMAIN, dropTables: true);

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
    createFakeDomainCheckout(DOMAIN_INSTALLER_FIXTURE_DOMAIN, 'zz_install_table', 'zz_install.option');

    domainInstaller()->disable(DOMAIN_INSTALLER_FIXTURE_DOMAIN);
    expect(DomainState::isDisabled(DOMAIN_INSTALLER_FIXTURE_DOMAIN))->toBeTrue();

    domainInstaller()->uninstall(DOMAIN_INSTALLER_FIXTURE_DOMAIN, dropTables: false);

    expect(DomainState::isDisabled(DOMAIN_INSTALLER_FIXTURE_DOMAIN))->toBeFalse();
});
