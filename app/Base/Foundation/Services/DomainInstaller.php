<?php

namespace App\Base\Foundation\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Installs, disables, and uninstalls non-Core domains.
 *
 * A fresh framework clone ships Base + Core only. Each domain is a nested
 * git checkout mounted at app/Modules/{Domain}; installing clones the repo
 * from the catalog (config: domains.catalog) and runs pending migrations,
 * uninstalling deletes the checkout and — only when explicitly requested —
 * drops the tables, ledger rows, and settings the deleted code claimed.
 *
 * Uninstall cleanup goes through DomainResidueScanner's re-validating
 * mutators, so anything still claimed by other installed code survives
 * even when this domain's migrations declared it.
 */
class DomainInstaller
{
    public function __construct(
        private readonly DomainResidueScanner $scanner,
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
     * @return list<array{name: string, modules: list<string>, disabled: bool, git: array{hasGit: bool, dirty: bool, unpushed: int}}>
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
                'git' => $this->gitState($path),
            ];
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

        $clone = Process::path(base_path())
            ->timeout(300)
            ->run(['git', 'clone', $entry['repo'], $path]);

        $log[] = '$ git clone '.$entry['repo'];
        $log[] = trim($clone->output()."\n".$clone->errorOutput());

        if (! $clone->successful()) {
            return ['ok' => false, 'log' => implode("\n", array_filter($log))];
        }

        // Stale disabled flag from a previous uninstall must not mute the new checkout.
        DomainState::enable($domain);

        $migrate = Process::path(base_path())
            ->timeout(600)
            ->run([PHP_BINARY, 'artisan', 'migrate', '--force']);

        $log[] = '$ php artisan migrate --force';
        $log[] = trim($migrate->output()."\n".$migrate->errorOutput());

        return [
            'ok' => $migrate->successful(),
            'log' => implode("\n", array_filter($log)),
        ];
    }

    public function disable(string $domain): void
    {
        $this->assertInstalled($domain);

        DomainState::disable($domain);
    }

    public function enable(string $domain): void
    {
        $this->assertInstalled($domain);

        DomainState::enable($domain);
    }

    /**
     * Delete the domain checkout; optionally drop the database state it claimed.
     *
     * @return array{droppedTables: list<string>, prunedLedger: int, deletedSettings: int}
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
        $settingKeys = DomainResidueScanner::settingKeysDeclaredIn(
            glob($path.'/*/Config/settings.php') ?: [],
        );

        if (! File::deleteDirectory($path)) {
            throw new InvalidArgumentException("Could not delete [$path]. Check filesystem permissions.");
        }

        DomainState::enable($domain);

        if (! $dropTables) {
            return ['droppedTables' => [], 'prunedLedger' => 0, 'deletedSettings' => 0];
        }

        $dropped = $this->scanner->dropTables($claimedTables);

        return [
            'droppedTables' => $dropped['dropped'],
            'prunedLedger' => $this->scanner->pruneLedger($ledgerEntries),
            'deletedSettings' => $this->scanner->deleteSettings($settingKeys),
        ];
    }

    /**
     * @return array{hasGit: bool, dirty: bool, unpushed: int}
     */
    public function gitState(string $path): array
    {
        if (! is_dir($path.'/.git')) {
            return ['hasGit' => false, 'dirty' => false, 'unpushed' => 0];
        }

        $status = Process::path($path)->timeout(30)->run(['git', 'status', '--porcelain']);
        $unpushed = Process::path($path)->timeout(30)
            ->run(['git', 'rev-list', '--count', '--branches', '--not', '--remotes']);

        return [
            'hasGit' => true,
            'dirty' => trim($status->output()) !== '',
            'unpushed' => (int) trim($unpushed->output()),
        ];
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

    private function directoryIsEmpty(string $path): bool
    {
        return (scandir($path) ?: []) === ['.', '..'];
    }
}
