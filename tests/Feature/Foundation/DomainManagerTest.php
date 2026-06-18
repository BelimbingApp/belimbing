<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\DomainManager;
use App\Base\Foundation\Services\DomainState;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Livewire\Drawer\Utils;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const DOMAIN_MANAGER_FIXTURE_DOMAIN = 'ZzManaged';
const DOMAIN_MANAGER_FIXTURE_REPO = 'https://example.test/zz.git';
const DOMAIN_MANAGER_FIXTURE_DESCRIPTION = 'Fixture description.';
const DOMAIN_MANAGER_FIXTURE_TABLE = 'zz_managed_table';
const DOMAIN_MANAGER_FIXTURE_SETTING = 'zz_managed.option';
const DOMAIN_MANAGER_FIXTURE_PATH = 'Modules/'.DOMAIN_MANAGER_FIXTURE_DOMAIN;

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
});

afterEach(function (): void {
    File::deleteDirectory(app_path(DOMAIN_MANAGER_FIXTURE_PATH));
});

function configureManagedDomainCatalog(): void
{
    config(['domains.catalog' => [
        DOMAIN_MANAGER_FIXTURE_DOMAIN => [
            'repo' => DOMAIN_MANAGER_FIXTURE_REPO,
            'description' => DOMAIN_MANAGER_FIXTURE_DESCRIPTION,
        ],
    ]]);
}

function createManagedDomainCheckout(): void
{
    createFakeDomainCheckout(
        DOMAIN_MANAGER_FIXTURE_DOMAIN,
        DOMAIN_MANAGER_FIXTURE_TABLE,
        DOMAIN_MANAGER_FIXTURE_SETTING,
    );
}

function flushDomainManagerAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

it('renders the Business Domains page with installed domains and a residue pointer', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.update.business-domains.index'))
        ->assertOk()
        ->assertSee('Business Domains')
        ->assertSee('Installed business domains')
        ->assertSee(route('admin.system.database-residue.index'));
});

it('denies the page to users without the view capability', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.update.business-domains.index'))->assertForbidden();
});

it('shows catalog domains without a checkout as available to install', function (): void {
    $this->actingAs(createAdminUser());

    configureManagedDomainCatalog();

    Livewire::test(DomainManager::class)
        ->assertSee('Available business domains')
        ->assertSee(DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertSee(DOMAIN_MANAGER_FIXTURE_DESCRIPTION);
});

it('shows installed date and installer from retained audit actions', function (): void {
    $user = createAdminUser();
    $user->forceFill([
        'name' => 'Ada Admin',
        'email' => 'ada.admin@example.test',
    ])->save();

    $this->actingAs($user);

    createManagedDomainCheckout();

    AuditAction::query()->insert([
        'company_id' => $user->getCompanyId(),
        'actor_type' => PrincipalType::USER->value,
        'actor_id' => $user->id,
        'actor_role' => 'core_admin',
        'ip_address' => '127.0.0.1',
        'url' => route('admin.system.update.business-domains.index'),
        'user_agent' => 'Feature test',
        'event' => 'domain.install',
        'payload' => json_encode([
            'domain' => DOMAIN_MANAGER_FIXTURE_DOMAIN,
            'status' => 'succeeded',
            'actor_name' => 'Ada Admin',
            'actor_email' => 'ada.admin@example.test',
        ]),
        'trace_id' => 'D0MA1N1NSTAL',
        'is_retained' => true,
        'occurred_at' => Carbon::parse('2026-06-17 10:00:00', 'UTC'),
    ]);

    Livewire::test(DomainManager::class)
        ->assertSee('Ada Admin')
        ->assertSee('ada.admin@example.test')
        ->assertSeeHtml('datetime="2026-06-17T10:00:00+00:00"');
});

it('shows flashed domain action output in the run log overlay', function (): void {
    $this->actingAs(createAdminUser());

    session()->flash('command-log', "Line one\nLine two");

    Livewire::test(DomainManager::class)
        ->assertSee('Run log')
        ->assertSee('Last business-domain action')
        ->assertSee('h-72', false)
        ->assertSee('scrollHeight', false)
        ->assertSee('Line one')
        ->assertSee('Line two');
});

it('installs an available domain and redirects back', function (): void {
    $user = createAdminUser();
    $user->forceFill([
        'name' => 'Install Admin',
        'email' => 'install.admin@example.test',
    ])->save();
    $this->actingAs($user);

    configureManagedDomainCatalog();

    Process::fake();

    Livewire::test(DomainManager::class)
        ->call('install', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertRedirect(route('admin.system.update.business-domains.index'));

    Process::assertRan(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAIN_MANAGER_FIXTURE_REPO, app_path(DOMAIN_MANAGER_FIXTURE_PATH)]);

    flushDomainManagerAuditBuffer();

    $action = AuditAction::query()->where('event', 'domain.install')->firstOrFail();

    expect($action->actor_type)->toBe(PrincipalType::USER->value)
        ->and($action->actor_id)->toBe($user->id)
        ->and($action->is_retained)->toBeTrue()
        ->and($action->payload['domain'])->toBe(DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->and($action->payload['actor_name'])->toBe('Install Admin')
        ->and($action->payload['actor_email'])->toBe('install.admin@example.test');
});

it('redirects stale Livewire domain actions to login before running them', function (): void {
    $this->actingAs(createAdminUser());

    configureManagedDomainCatalog();

    $response = $this->get(route('admin.system.update.business-domains.index'))->assertOk();
    $snapshot = Utils::extractAttributeDataFromHtml($response->getContent(), 'wire:snapshot');
    $csrf = csrf_token();

    Process::fake();

    $payload = [
        '_token' => $csrf,
        'components' => [[
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'updates' => [],
            'calls' => [[
                'method' => 'install',
                'params' => [DOMAIN_MANAGER_FIXTURE_DOMAIN],
            ]],
        ]],
    ];

    $this->actingAsGuest();
    $this->flushSession();
    $this->withSession(['_token' => $csrf]);

    $this->withHeaders([
        'X-Livewire' => '1',
        'X-CSRF-TOKEN' => $csrf,
    ])->postJson(app('livewire')->getUpdateUri(), $payload)
        ->assertRedirect(route('login'));

    Process::assertDidntRun(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAIN_MANAGER_FIXTURE_REPO, app_path(DOMAIN_MANAGER_FIXTURE_PATH)]);
    expect(AuditAction::query()->where('event', 'domain.install')->doesntExist())->toBeTrue();
});

it('disables and re-enables an installed domain', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();

    Livewire::test(DomainManager::class)
        ->call('disable', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertSessionHas('command-log')
        ->assertRedirect(route('admin.system.update.business-domains.index'));

    expect(DomainState::isDisabled(DOMAIN_MANAGER_FIXTURE_DOMAIN))->toBeTrue();

    Livewire::test(DomainManager::class)
        ->call('enable', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->assertSessionHas('command-log')
        ->assertRedirect(route('admin.system.update.business-domains.index'));

    expect(DomainState::isDisabled(DOMAIN_MANAGER_FIXTURE_DOMAIN))->toBeFalse();
});

it('disables domain action buttons while their Livewire action is running', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();
    DomainState::disable(DOMAIN_MANAGER_FIXTURE_DOMAIN);

    Livewire::test(DomainManager::class)
        ->assertSee('wire:loading.attr="disabled"', false)
        ->assertSee('Enabling…')
        ->assertSee('Opening…');
});

it('refuses to uninstall without the exact typed phrase', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged please')
        ->call('uninstall')
        ->assertHasErrors('uninstallPhrase');

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeTrue();
});

it('uninstalls keeping the database when the keep phrase is typed', function (): void {
    $user = createAdminUser();
    $user->forceFill([
        'name' => 'Uninstall Admin',
        'email' => 'uninstall.admin@example.test',
    ])->save();
    $this->actingAs($user);

    createManagedDomainCheckout();
    Schema::create(DOMAIN_MANAGER_FIXTURE_TABLE, fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.update.business-domains.index'));

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeFalse()
        ->and(Schema::hasTable(DOMAIN_MANAGER_FIXTURE_TABLE))->toBeTrue();

    flushDomainManagerAuditBuffer();

    $action = AuditAction::query()->where('event', 'domain.uninstall')->firstOrFail();

    expect($action->actor_type)->toBe(PrincipalType::USER->value)
        ->and($action->actor_id)->toBe($user->id)
        ->and($action->is_retained)->toBeTrue()
        ->and($action->payload['domain'])->toBe(DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->and($action->payload['drop_tables'])->toBeFalse()
        ->and($action->payload['actor_name'])->toBe('Uninstall Admin');
});

it('uninstalls and drops tables when the drop phrase is typed', function (): void {
    $this->actingAs(createAdminUser());

    createManagedDomainCheckout();
    Schema::create(DOMAIN_MANAGER_FIXTURE_TABLE, fn ($table) => $table->id());

    Livewire::test(DomainManager::class)
        ->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)
        ->set('uninstallPhrase', 'uninstall zzmanaged and drop all tables')
        ->call('uninstall')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.system.update.business-domains.index'));

    expect(is_dir(app_path(DOMAIN_MANAGER_FIXTURE_PATH)))->toBeFalse()
        ->and(Schema::hasTable(DOMAIN_MANAGER_FIXTURE_TABLE))->toBeFalse();
});

it('blocks install, disable, and uninstall for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());

    configureManagedDomainCatalog();

    Process::fake();

    Livewire::test(DomainManager::class)->call('install', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();
    Livewire::test(DomainManager::class)->call('disable', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();
    Livewire::test(DomainManager::class)->call('openUninstall', DOMAIN_MANAGER_FIXTURE_DOMAIN)->assertForbidden();

    // Rendering legitimately runs `git status` on installed checkouts; what
    // must never have run without the manage capability is the clone.
    Process::assertDidntRun(fn ($process): bool => gitCommandWithoutConfig($process->command) === ['git', 'clone', DOMAIN_MANAGER_FIXTURE_REPO, app_path(DOMAIN_MANAGER_FIXTURE_PATH)]);
});
