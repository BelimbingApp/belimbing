<?php

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzAllowlistedProbeCommand;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzScopedProbeCommand;
use App\Domains\ZzDomainCmd\Fixture\Console\Commands\ZzUnscopedProbeCommand;
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

it('declares a justified allowlist for required-domain commands that are not yet migrated', function (): void {
    expect(config('domain_commands.tenant_scope.required_domains'))
        ->toBe(['People', 'PeopleConnector']);

    $allowlist = config('domain_commands.tenant_scope.allowlist');
    expect($allowlist)->toBeArray()->not->toBeEmpty();

    foreach ($allowlist as $name => $reason) {
        expect($name)->toBeString()->not->toBeEmpty()
            ->and($reason)->toBeString()->and(trim($reason))->not->toBe('');
    }
});
