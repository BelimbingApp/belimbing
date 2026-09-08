<?php

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Services\DomainCommandTenantAudit;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzAllowlistedProbeCommand;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzScopedProbeCommand;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzUnscopedProbeCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

const DOMAIN_CMD_AUDIT_FIXTURE_ROOT = 'app/Domains/ZzDomainCmd/Fixture';

function writeDomainCommandAuditFixtures(): void
{
    $root = base_path(DOMAIN_CMD_AUDIT_FIXTURE_ROOT.'/Console/Commands');
    File::ensureDirectoryExists($root);

    File::put($root.'/ZzUnscopedProbeCommand.php', <<<'PHP'
<?php

namespace App\Domains\ZzDomainCmd\Fixture\Console\Commands;

use Illuminate\Console\Command;

final class ZzUnscopedProbeCommand extends Command
{
    protected $signature = 'zz-domain-cmd:unscoped';

    protected $description = 'Fixture: Domain command without TenantScopedCommand';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP);

    File::put($root.'/ZzScopedProbeCommand.php', <<<'PHP'
<?php

namespace App\Domains\ZzDomainCmd\Fixture\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;

final class ZzScopedProbeCommand extends TenantScopedCommand
{
    protected $signature = 'zz-domain-cmd:scoped';

    protected $description = 'Fixture: Domain command extending TenantScopedCommand';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP);

    File::put($root.'/ZzAllowlistedProbeCommand.php', <<<'PHP'
<?php

namespace App\Domains\ZzDomainCmd\Fixture\Console\Commands;

use Illuminate\Console\Command;

final class ZzAllowlistedProbeCommand extends Command
{
    protected $signature = 'zz-domain-cmd:allowlisted';

    protected $description = 'Fixture: Domain command allowlisted without TenantScopedCommand';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
PHP);

    require_once $root.'/ZzUnscopedProbeCommand.php';
    require_once $root.'/ZzScopedProbeCommand.php';
    require_once $root.'/ZzAllowlistedProbeCommand.php';

    Artisan::registerCommand(app(ZzUnscopedProbeCommand::class));
    Artisan::registerCommand(app(ZzScopedProbeCommand::class));
    Artisan::registerCommand(app(ZzAllowlistedProbeCommand::class));
}

afterEach(function (): void {
    File::deleteDirectory(base_path('app/Domains/ZzDomainCmd'));
    app(TenantContext::class)->clear();
    CarbonImmutable::setTestNow();
});

it('lists a Domain command that does not extend TenantScopedCommand as an audit failure', function (): void {
    writeDomainCommandAuditFixtures();
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', []);

    $this->artisan('blb:domain-commands', ['--audit' => true])
        ->expectsOutputToContain('missing TenantScopedCommand')
        ->expectsOutputToContain('zz-domain-cmd:unscoped')
        ->assertFailed();
});

it('omits an allowlisted Domain command that has a non-empty reason', function (): void {
    writeDomainCommandAuditFixtures();
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:allowlisted' => 'Fixture exempted for audit coverage.',
        'zz-domain-cmd:unscoped' => 'Temporarily exempted while asserting allowlist behavior.',
    ]);

    $this->artisan('blb:domain-commands', ['--audit' => true])
        ->expectsOutputToContain('Domain command tenant-scope audit passed.')
        ->assertSuccessful();

    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:allowlisted' => '   ',
        'zz-domain-cmd:unscoped' => 'Temporarily exempted while asserting allowlist behavior.',
    ]);

    $this->artisan('blb:domain-commands', ['--audit' => true, '--json' => true])
        ->assertFailed();
});

it('treats TenantScopedCommand subclasses as satisfying the audit', function (): void {
    writeDomainCommandAuditFixtures();
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:unscoped' => 'Not under test here.',
        'zz-domain-cmd:allowlisted' => 'Not under test here.',
    ]);

    $this->artisan('blb:domain-commands', ['--audit' => true])->assertSuccessful();

    expect(is_subclass_of(
        ZzScopedProbeCommand::class,
        TenantScopedCommand::class,
    ))->toBeTrue();
});

it('exempts a dated allowlist entry until its expiry and not after', function (): void {
    writeDomainCommandAuditFixtures();
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:unscoped' => 'Not under test here.',
        'zz-domain-cmd:allowlisted' => ['reason' => 'Dated fixture exemption.', 'expires' => '2026-10-07'],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 23:59:00'));
    $this->artisan('blb:domain-commands', ['--audit' => true])
        ->expectsOutputToContain('Domain command tenant-scope audit passed.')
        ->assertSuccessful();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 00:00:01'));
    $this->artisan('blb:domain-commands', ['--audit' => true])
        ->expectsOutputToContain('zz-domain-cmd:allowlisted')
        ->assertFailed();
});

it('does not exempt a dated entry with a blank reason, a missing expiry, or a malformed expiry', function (string $key, mixed $entry): void {
    writeDomainCommandAuditFixtures();
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:unscoped' => 'Not under test here.',
        'zz-domain-cmd:allowlisted' => $entry,
    ]);

    $this->artisan('blb:domain-commands', ['--audit' => true, '--json' => true])->assertFailed();
})->with([
    'blank reason' => ['blank', ['reason' => '  ', 'expires' => '2999-01-01']],
    'missing expiry' => ['missing', ['reason' => 'No date.']],
    'malformed expiry' => ['malformed', ['reason' => 'Bad date.', 'expires' => 'soon']],
    'non-canonical expiry' => ['loose', ['reason' => 'Loose date.', 'expires' => '2999-1-1']],
]);

it('declares a dated, justified allowlist for required-domain commands that are not yet migrated', function (): void {
    expect(config('domain_commands.tenant_scope.required_domains'))
        ->toBe(['People', 'PeopleConnector']);

    $allowlist = config('domain_commands.tenant_scope.allowlist');
    expect($allowlist)->toBeArray()->not->toBeEmpty();

    foreach ($allowlist as $name => $entry) {
        expect($name)->toBeString()->not->toBeEmpty()
            ->and($entry)->toBeArray()
            ->and($entry['reason'] ?? null)->toBeString()
            ->and(trim($entry['reason']))->not->toBe('')
            ->and($entry['expires'] ?? null)->toMatch('/^\d{4}-\d{2}-\d{2}$/');

        // A shipped exemption is a rollout, not a permanent hole: it lapses
        // within ninety days of the entry being written.
        $expiry = CarbonImmutable::createFromFormat('Y-m-d', $entry['expires']);
        expect($expiry)->not->toBeFalse()
            ->and($expiry->isAfter(CarbonImmutable::parse('2026-09-07')))->toBeTrue()
            ->and($expiry->isBefore(CarbonImmutable::parse('2026-09-07')->addDays(90)))->toBeTrue();
    }
});

it('warns only while an active exemption is within the calendar-day window', function (string $today, ?int $daysLeft, int $exit): void {
    writeDomainCommandAuditFixtures();
    CarbonImmutable::setTestNow(CarbonImmutable::parse($today.' 12:00:00'));
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:unscoped' => 'Not under test.',
        'zz-domain-cmd:allowlisted' => ['reason' => 'Migration pending.', 'expires' => '2026-10-07'],
    ]);

    $this->withoutMockingConsoleOutput();
    expect(Artisan::call('blb:domain-commands', ['--audit' => true, '--json' => true, '--warn-days' => '14']))->toBe($exit);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['expiring'])->toBe($daysLeft === null ? [] : [[
        'name' => 'zz-domain-cmd:allowlisted', 'domain' => 'ZzDomainCmd', 'module' => 'Fixture',
        'expires' => '2026-10-07', 'days_left' => $daysLeft, 'reason' => 'Migration pending.', 'stale' => false,
    ]]);
    expect(array_column($result['failures'], 'name'))->toBe($exit === 0 ? [] : ['zz-domain-cmd:allowlisted']);
})->with([
    'outside window' => ['2026-09-17', null, 0],
    'inside window' => ['2026-09-27', 10, 0],
    'last exempt day' => ['2026-10-07', 0, 0],
    'lapsed' => ['2026-10-08', null, 1],
]);

it('lists a stale exemption without failing and omits migrated and malformed entries', function (): void {
    writeDomainCommandAuditFixtures();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27'));
    config()->set('domain_commands.tenant_scope.required_domains', ['ZzDomainCmd']);
    config()->set('domain_commands.tenant_scope.allowlist', [
        'zz-domain-cmd:unscoped' => 'Not under test.',
        'zz-domain-cmd:allowlisted' => 'Not under test.',
        'zz-domain-cmd:gone' => ['reason' => 'Removed command.', 'expires' => '2026-10-07'],
        'zz-domain-cmd:scoped' => ['reason' => 'Already migrated.', 'expires' => '2026-10-07'],
        'invalid' => ['reason' => 'Bad date.', 'expires' => '2026-09-31'],
    ]);
    expect(app(DomainCommandTenantAudit::class)->expiring(14))->toBe([[
        'name' => 'zz-domain-cmd:gone', 'domain' => null, 'module' => null,
        'expires' => '2026-10-07', 'days_left' => 10, 'reason' => 'Removed command.', 'stale' => true,
    ]]);
    $this->artisan('blb:domain-commands', ['--audit' => true])
        ->expectsOutputToContain('Expiring exemptions')
        ->expectsOutputToContain('stale')
        ->assertSuccessful();
});

it('refuses a non-positive or non-integer warning window', function (string $window): void {
    $this->artisan('blb:domain-commands', ['--audit' => true, '--warn-days' => $window])
        ->expectsOutputToContain('--warn-days must be a positive integer.')
        ->assertFailed();
})->with(['0', 'abc', '-1', '1.5']);
