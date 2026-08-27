<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Domains;
use App\Base\Foundation\Services\SourceRepositoryInstaller;
use App\Base\Software\Inventory\InstalledSource;
use App\Base\Software\Services\GitHubTokenStore;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const SOURCE_REPOSITORY_INSTALL_URL = 'https://github.com/zz-private/blb-payroll-malaysia.git';
const SOURCE_REPOSITORY_INSTALL_TOKEN_OWNER = 'zz-credential-owner';
const SOURCE_REPOSITORY_INSTALL_TOKEN = 'ghp_source_install_0123456789';
const SOURCE_REPOSITORY_INSTALL_FOLDER = 'ZzPayrollMalaysia';
const SOURCE_REPOSITORY_INSTALL_MODULE = 'Payroll';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
});

afterEach(function (): void {
    File::deleteDirectory(app_path('Domains/'.SOURCE_REPOSITORY_INSTALL_FOLDER));
    File::deleteDirectory(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER));
});

function createSourceRepositoryInstallCheckout(string $kind, bool $validManifest = true, ?string $namespaceKind = null): string
{
    $root = $kind === InstalledSource::KIND_DOMAIN ? 'Domains' : 'Extensions';
    $path = app_path($root.'/'.SOURCE_REPOSITORY_INSTALL_FOLDER.'/'.SOURCE_REPOSITORY_INSTALL_MODULE);
    File::ensureDirectoryExists($path.'/Database/Migrations');

    $namespaceRoot = $namespaceKind ?? $root;
    File::put(
        $path.'/ServiceProvider.php',
        "<?php\n\nnamespace App\\{$namespaceRoot}\\".SOURCE_REPOSITORY_INSTALL_FOLDER.'\\'.SOURCE_REPOSITORY_INSTALL_MODULE.";\n\nclass ServiceProvider {}\n",
    );

    if ($validManifest) {
        File::put($path.'/composer.json', json_encode([
            'name' => 'zz-private/payroll-malaysia',
            'extra' => [
                'blb' => [
                    'module' => 'zz-payroll/payroll',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    return dirname($path);
}

it('installs a private repository as a domain with the explicitly selected credential', function (): void {
    app(GitHubTokenStore::class)->saveToken(SOURCE_REPOSITORY_INSTALL_TOKEN_OWNER, SOURCE_REPOSITORY_INSTALL_TOKEN);
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_DOMAIN);
        }

        return Process::result();
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_DOMAIN,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
        SOURCE_REPOSITORY_INSTALL_TOKEN_OWNER,
    );

    $expectedAuthEnvironment = [
        'GIT_TERMINAL_PROMPT' => '0',
        'GIT_ASKPASS' => '',
        'GIT_CONFIG_COUNT' => '1',
        'GIT_CONFIG_KEY_0' => 'http.extraHeader',
        'GIT_CONFIG_VALUE_0' => 'Authorization: Basic '.base64_encode('x-access-token:'.SOURCE_REPOSITORY_INSTALL_TOKEN),
    ];

    expect($result['ok'])->toBeTrue()
        ->and(is_dir(app_path('Domains/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeTrue()
        ->and(app(DomainRuntimeReloader::class)->calls)->toBe(1);

    Process::assertRan(fn ($process): bool => in_array('clone', $process->command, true)
        && in_array('https://github.com/zz-private/blb-payroll-malaysia', $process->command, true)
        && $process->environment === $expectedAuthEnvironment);
    Process::assertDidntRun(fn ($process): bool => collect($process->command)
        ->contains(fn (string $argument): bool => str_contains($argument, SOURCE_REPOSITORY_INSTALL_TOKEN)));
    Process::assertRan(fn ($process): bool => in_array('migrate', $process->command, true)
        && in_array('--path=app/Domains/'.SOURCE_REPOSITORY_INSTALL_FOLDER.'/'.SOURCE_REPOSITORY_INSTALL_MODULE.'/Database/Migrations', $process->command, true));
});

it('installs a public repository through the operator form as an extension', function (): void {
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_EXTENSION);
        }

        return Process::result();
    });
    $this->actingAs(createAdminUser());

    Livewire::test(Domains::class, ['tab' => 'available'])
        ->set('repositoryUrl', SOURCE_REPOSITORY_INSTALL_URL)
        ->set('repositoryKind', InstalledSource::KIND_EXTENSION)
        ->set('repositoryFolder', SOURCE_REPOSITORY_INSTALL_FOLDER)
        ->set('repositoryCredentialOwner', '')
        ->call('installRepository')
        ->assertRedirect(route('admin.system.software.domains.index'));

    expect(is_dir(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeTrue();
});

it('rejects non-GitHub and credential-bearing URLs before git runs', function (string $url): void {
    Process::fake();

    expect(fn () => app(SourceRepositoryInstaller::class)->install(
        $url,
        InstalledSource::KIND_EXTENSION,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    ))->toThrow(InvalidArgumentException::class);

    Process::assertNothingRan();
})->with([
    'local file' => 'file:///tmp/source',
    'other host' => 'https://example.com/owner/repo',
    'embedded credential' => 'https://token@github.com/owner/repo',
    'custom port' => 'https://github.com:8443/owner/repo',
    'query string' => 'https://github.com/owner/repo?ref=main',
    'fragment' => 'https://github.com/owner/repo#readme',
    'repository subpath' => 'https://github.com/owner/repo/tree/main',
]);

it('removes a checkout that fails manifest validation before migrations run', function (): void {
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_EXTENSION, validManifest: false);
        }

        return Process::result();
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_EXTENSION,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['cleaned_up'])->toBeTrue()
        ->and($result['log'])->toContain('Manifest validation failed')
        ->and(is_dir(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeFalse();

    Process::assertDidntRun(fn ($process): bool => in_array('migrate', $process->command, true));
});

it('rejects a checkout whose namespace does not match the chosen placement', function (): void {
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(
                InstalledSource::KIND_DOMAIN,
                namespaceKind: 'Extensions',
            );
        }

        return Process::result();
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_DOMAIN,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['cleaned_up'])->toBeTrue()
        ->and($result['log'])->toContain('selected placement');
});

it('rolls back the source migration batch and removes the checkout after migration failure', function (): void {
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_EXTENSION);

            return Process::result();
        }

        if (in_array('migrate', $process->command, true)) {
            return Process::result(errorOutput: 'migration failed', exitCode: 1);
        }

        return Process::result(output: 'rolled back');
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_EXTENSION,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['cleaned_up'])->toBeTrue()
        ->and(is_dir(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeFalse();

    Process::assertRan(fn ($process): bool => in_array('migrate:rollback', $process->command, true)
        && collect($process->command)->contains(fn (string $argument): bool => str_starts_with($argument, '--batch=')));
});

it('keeps the checkout when migration rollback cannot be verified', function (): void {
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_EXTENSION);

            return Process::result();
        }

        return Process::result(errorOutput: 'failed', exitCode: 1);
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_EXTENSION,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['cleaned_up'])->toBeFalse()
        ->and($result['log'])->toContain('checkout remains')
        ->and(is_dir(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeTrue();
});

it('keeps the installed source when runtime reload fails after successful migrations', function (): void {
    app()->instance(DomainRuntimeReloader::class, new class implements DomainRuntimeReloader
    {
        public function reloadAfterDomainChange(): array
        {
            throw new RuntimeException('reload unavailable');
        }
    });
    Process::fake(function ($process) {
        if (in_array('clone', $process->command, true)) {
            createSourceRepositoryInstallCheckout(InstalledSource::KIND_EXTENSION);
        }

        return Process::result();
    });

    $result = app(SourceRepositoryInstaller::class)->install(
        SOURCE_REPOSITORY_INSTALL_URL,
        InstalledSource::KIND_EXTENSION,
        SOURCE_REPOSITORY_INSTALL_FOLDER,
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['cleaned_up'])->toBeFalse()
        ->and($result['log'])->toContain('runtime reload failed')
        ->and(is_dir(app_path('Extensions/'.SOURCE_REPOSITORY_INSTALL_FOLDER)))->toBeTrue();
});

it('blocks repository installation for users without domain-management capability', function (): void {
    $this->actingAs(User::factory()->create());
    Process::fake();

    Livewire::test(Domains::class)
        ->set('repositoryUrl', SOURCE_REPOSITORY_INSTALL_URL)
        ->set('repositoryFolder', SOURCE_REPOSITORY_INSTALL_FOLDER)
        ->call('installRepository')
        ->assertForbidden();

    Process::assertDidntRun(fn ($process): bool => in_array('clone', $process->command, true));
});
