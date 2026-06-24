<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\DomainLifecycleLedger;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Events\DomainLifecycleAction;
use App\Base\Settings\Models\Setting;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\PhpCli;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Installs, disables, and uninstalls non-Core domains.
 *
 * A fresh Belimbing clone ships the Platform Baseline (Base + Core). Each
 * add-in domain is a nested git checkout mounted at app/Modules/{Domain};
 * installing clones the repo from the catalog (config: domains.catalog) and
 * runs pending migrations; uninstalling deletes the checkout and — only when
 * explicitly requested — drops the tables, ledger rows, and settings the
 * deleted code claimed.
 *
 * Uninstall cleanup goes through DomainResidueScanner's re-validating
 * mutators, so anything still claimed by other installed code survives
 * even when this domain's migrations declared it.
 */
class DomainInstaller
{
    public function __construct(
        private readonly DomainResidueScanner $scanner,
        private readonly DomainLifecycleLedger $lifecycleLedger,
        private readonly DomainRuntimeReloader $runtimeReloader,
        private readonly NestedCheckoutGitState $gitState,
    ) {}

    /**
     * Installable domains: catalog entries without a checkout.
     *
     * @return array<string, array{repo: string, description: string}>
     */
    public function available(): array
    {
        $available = [];

        foreach ((array) config('domains.catalog', []) as $domain => $entry) {
            if (! is_string($domain) || $this->isInstalled($domain)) {
                continue;
            }

            $available[$domain] = [
                'repo' => (string) ($entry['repo'] ?? ''),
                'description' => (string) ($entry['description'] ?? ''),
            ];
        }

        return $available;
    }

    /**
     * Installed non-Core domains with their runtime state.
     *
     * @return list<array{name: string, modules: list<string>, disabled: bool, installation: array{occurred_at: string, actor_type: string, actor_id: int, actor_name: string|null, actor_email: string|null, status: string|null}|null, git: array{hasGit: bool, dirty: bool, unpushed: int}}>
     */
    public function installed(): array
    {
        $domains = [];

        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);

            if ($name === 'Core' || $this->directoryIsEmpty($path)) {
                continue;
            }

            $modules = [];
            foreach (glob($path.'/*/ServiceProvider.php') ?: [] as $provider) {
                $modules[] = basename(dirname($provider));
            }

            $domains[] = [
                'name' => $name,
                'modules' => $modules,
                'disabled' => DomainState::isDisabled($name),
                'installation' => null,
                'git' => $this->gitState->inspect($path),
            ];
        }

        $installations = $this->lifecycleLedger->latestInstallations(array_column($domains, 'name'));

        foreach ($domains as $index => $domain) {
            $domains[$index]['installation'] = $installations[$domain['name']] ?? null;
        }

        return $domains;
    }

    public function isInstalled(string $domain): bool
    {
        $path = $this->domainPath($domain);

        return is_dir($path) && ! $this->directoryIsEmpty($path);
    }

    /**
     * Whether any non-Core domain is checked out. Cheap (no git calls),
     * safe for hot paths like the post-login redirect.
     */
    public function hasAnyInstalled(): bool
    {
        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [] as $path) {
            if (basename($path) !== 'Core' && ! $this->directoryIsEmpty($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clone the domain repo and run its pending migrations.
     *
     * Migrations run in a fresh artisan subprocess so the new checkout is
     * picked up by discovery — the current process booted without it.
     *
     * @return array{ok: bool, log: string}
     */
    public function install(string $domain): array
    {
        $entry = config('domains.catalog.'.$domain);

        if (! is_array($entry) || ! is_string($entry['repo'] ?? null)) {
            throw new InvalidArgumentException("Domain [$domain] is not in the catalog.");
        }

        if ($this->isInstalled($domain)) {
            throw new InvalidArgumentException("Domain [$domain] is already installed.");
        }

        $path = $this->domainPath($domain);

        if (is_dir($path)) {
            // Empty leftover mount point; git clone needs it gone or empty.
            @rmdir($path);
        }

        $log = [];

        $clone = (new GitRepository(base_path()))
            ->run(['clone', $entry['repo'], $path], timeout: 300);

        $log[] = '$ git clone '.$entry['repo'];
        $log[] = trim($clone->output."\n".$clone->error);

        if (! $clone->ok) {
            event(new DomainLifecycleAction($domain, 'install', 'clone_failed', [
                'repo' => $entry['repo'],
                'exit_code' => $clone->exitCode,
            ]));

            return ['ok' => false, 'log' => implode("\n", array_filter($log))];
        }

        // Stale disabled flag from a previous uninstall must not mute the new checkout.
        DomainState::enable($domain);

        $migrate = Process::path(base_path())
            ->timeout(600)
            ->run(PhpCli::current()->artisan(['migrate', '--force']));

        $log[] = '$ php artisan migrate --force';
        $log[] = trim($migrate->output()."\n".$migrate->errorOutput());

        event(new DomainLifecycleAction($domain, 'install', $migrate->successful() ? 'succeeded' : 'migration_failed', [
            'repo' => $entry['repo'],
            'exit_code' => $migrate->exitCode(),
        ]));

        $log = array_merge($log, $this->reloadRuntimeLog());

        return [
            'ok' => $migrate->successful(),
            'log' => implode("\n", array_filter($log)),
        ];
    }

    /**
     * @return list<string>
     */
    public function disable(string $domain): array
    {
        $this->assertInstalled($domain);

        DomainState::disable($domain);

        event(new DomainLifecycleAction($domain, 'disable', 'succeeded'));

        return $this->reloadRuntimeLog();
    }

    /**
     * @return list<string>
     */
    public function enable(string $domain): array
    {
        $this->assertInstalled($domain);

        DomainState::enable($domain);

        event(new DomainLifecycleAction($domain, 'enable', 'succeeded'));

        return $this->reloadRuntimeLog();
    }

    /**
     * Delete the domain checkout; optionally drop the database state it claimed.
     *
     * @return array{droppedTables: list<string>, prunedLedger: int, deletedSettings: int, reloadLog: list<string>}
     */
    public function uninstall(string $domain, bool $dropTables): array
    {
        $this->assertInstalled($domain);

        $path = $this->domainPath($domain);

        // Capture what the checkout claims before its files disappear.
        $migrationFiles = glob($path.'/*/Database/Migrations/*.php') ?: [];
        $claimedTables = DomainResidueScanner::tablesCreatedIn($migrationFiles);
        $ledgerEntries = array_map(
            fn (string $file): string => basename($file, '.php'),
            $migrationFiles,
        );
        $settingKeys = $this->settingKeysInDatabase(
            DomainResidueScanner::settingKeysDeclaredIn(glob($path.'/*/Config/settings.php') ?: []),
        );

        if (! File::deleteDirectory($path)) {
            throw new InvalidArgumentException("Could not delete [$path]. Check filesystem permissions.");
        }

        DomainState::enable($domain);

        if (! $dropTables) {
            event(new DomainLifecycleAction($domain, 'uninstall', 'succeeded', [
                'drop_tables' => false,
                'dropped_tables' => 0,
                'pruned_ledger' => 0,
                'deleted_settings' => 0,
            ]));

            return [
                'droppedTables' => [],
                'prunedLedger' => 0,
                'deletedSettings' => 0,
                'reloadLog' => $this->reloadRuntimeLog(),
            ];
        }

        $dropped = $this->scanner->dropTables($claimedTables);

        $result = [
            'droppedTables' => $dropped['dropped'],
            'prunedLedger' => $this->scanner->pruneLedger($ledgerEntries),
            'deletedSettings' => $this->scanner->deleteSettings($settingKeys),
        ];

        event(new DomainLifecycleAction($domain, 'uninstall', 'succeeded', [
            'drop_tables' => true,
            'dropped_tables' => count($result['droppedTables']),
            'pruned_ledger' => $result['prunedLedger'],
            'deleted_settings' => $result['deletedSettings'],
        ]));

        return [
            ...$result,
            'reloadLog' => $this->reloadRuntimeLog(),
        ];
    }

    /**
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    public function gitState(string $path): array
    {
        return $this->gitState->inspect($path);
    }

    /**
     * Expand a declaration list (exact keys and `prefix.*` wildcards) into
     * the keys actually present in the settings table. Must run before the
     * checkout is deleted — afterwards nothing remembers the wildcards.
     *
     * @param  list<string>  $declarations
     * @return list<string>
     */
    private function settingKeysInDatabase(array $declarations): array
    {
        return Setting::query()
            ->select('key')
            ->distinct()
            ->pluck('key')
            ->filter(fn (string $key): bool => DomainResidueScanner::settingKeyIsDeclared($key, $declarations))
            ->values()
            ->all();
    }

    private function assertInstalled(string $domain): void
    {
        if ($domain === 'Core' || preg_match('/^[A-Z][A-Za-z0-9]*$/', $domain) !== 1) {
            throw new InvalidArgumentException("Invalid domain name [$domain].");
        }

        if (! $this->isInstalled($domain)) {
            throw new InvalidArgumentException("Domain [$domain] is not installed.");
        }
    }

    private function domainPath(string $domain): string
    {
        return app_path('Modules/'.$domain);
    }

    /**
     * @return list<string>
     */
    private function reloadRuntimeLog(): array
    {
        return $this->runtimeReloader->reloadAfterDomainChange();
    }

    private function directoryIsEmpty(string $path): bool
    {
        return (scandir($path) ?: []) === ['.', '..'];
    }
}
