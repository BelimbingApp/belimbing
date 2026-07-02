<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Base\Support\Git\GitRepository;
use App\Base\Support\PhpCli;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Installs and uninstalls private licensee extensions under `extensions/{folder}`.
 *
 * Each catalog entry (config: extensions.catalog) is a nested git repo cloned
 * into `extensions/{folder}`; one repo may contain several modules. Private
 * repos are cloned with the GitHub token stored per owner by GitHub Access
 * (`integrations.github.token.{github-owner}`) — the owner is parsed from the
 * repo URL, so the folder may differ from the GitHub account.
 *
 * Parallel to DomainInstaller, but two levels deep (`extensions/{owner}/{module}`)
 * and credentialed. See docs/guides/extensions/private-extension-repositories.md.
 */
class ExtensionInstaller
{
    private const TOKEN_PREFIX = 'integrations.github.token.';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly DomainResidueScanner $scanner,
        private readonly DomainRuntimeReloader $runtimeReloader,
        private readonly NestedCheckoutGitState $gitState,
    ) {}

    /**
     * Installable catalog extensions without a checkout.
     *
     * @return array<string, array{repo: string, description: string, owner: string|null, has_token: bool}>
     */
    public function available(): array
    {
        $available = [];

        foreach ((array) config('extensions.catalog', []) as $folder => $entry) {
            if (! is_string($folder) || $this->isInstalled($folder)) {
                continue;
            }

            $repo = (string) ($entry['repo'] ?? '');
            $owner = $this->ownerFromRepo($repo);

            $available[$folder] = [
                'repo' => $repo,
                'description' => (string) ($entry['description'] ?? ''),
                'owner' => $owner,
                'has_token' => $owner !== null && $this->tokenForOwner($owner) !== null,
            ];
        }

        return $available;
    }

    /**
     * Installed extensions with their modules and git state.
     *
     * @return list<array{name: string, modules: list<string>, git: array{hasGit: bool, dirty: bool, unpushed: int}}>
     */
    public function installed(bool $includeGit = true): array
    {
        $extensions = [];

        foreach (glob(base_path('extensions/*'), GLOB_ONLYDIR) ?: [] as $path) {
            if ($this->directoryIsEmpty($path)) {
                continue;
            }

            $modules = [];
            foreach (glob($path.'/*/ServiceProvider.php') ?: [] as $provider) {
                $modules[] = basename(dirname($provider));
            }

            $extensions[] = [
                'name' => basename($path),
                'modules' => $modules,
                'git' => $includeGit ? $this->gitState->inspect($path) : $this->gitState->presence($path),
            ];
        }

        return $extensions;
    }

    public function isInstalled(string $folder): bool
    {
        $path = $this->extensionPath($folder);

        return is_dir($path) && ! $this->directoryIsEmpty($path);
    }

    /**
     * Clone the catalog repo into `extensions/{folder}` (token-authenticated for
     * private repos) and run its pending migrations in a fresh subprocess.
     *
     * @return array{ok: bool, log: string}
     */
    public function install(string $folder): array
    {
        $entry = config('extensions.catalog.'.$folder);

        if (! is_array($entry) || ! is_string($entry['repo'] ?? null) || $entry['repo'] === '') {
            throw new InvalidArgumentException("Extension [$folder] is not in the catalog.");
        }

        if ($this->isInstalled($folder)) {
            throw new InvalidArgumentException("Extension [$folder] is already installed.");
        }

        $this->assertFolderName($folder);

        $repo = $entry['repo'];
        $path = $this->extensionPath($folder);

        if (is_dir($path)) {
            // Empty leftover mount point; git clone needs it gone or empty.
            @rmdir($path);
        }

        $log = [];
        $owner = $this->ownerFromRepo($repo);
        $token = $owner !== null ? $this->tokenForOwner($owner) : null;

        $clone = (new GitRepository(base_path(), $token))
            ->run(['clone', $repo, $path], authenticated: true, timeout: 300);

        $log[] = '$ git clone '.$repo;
        $log[] = trim($clone->output."\n".$clone->error);

        if (! $clone->ok) {
            $log[] = (string) __('FAILED — could not clone :folder. Check the repository URL and that a GitHub token is stored for its owner under GitHub Access.', ['folder' => $folder]);

            return ['ok' => false, 'log' => implode("\n", array_filter($log))];
        }

        $migrate = Process::path(base_path())
            ->timeout(600)
            ->run(PhpCli::current()->artisan(['migrate', '--force']));

        $log[] = '$ php artisan migrate --force';
        $log[] = trim($migrate->output()."\n".$migrate->errorOutput());

        $log = array_merge($log, $this->reloadRuntimeLog());

        $log[] = $migrate->successful()
            ? (string) __('Done — extension :folder installed. Its modules are live from the next page load.', ['folder' => $folder])
            : (string) __('FAILED — migrations did not complete for :folder; review the output above.', ['folder' => $folder]);

        return [
            'ok' => $migrate->successful(),
            'log' => implode("\n", array_filter($log)),
        ];
    }

    /**
     * Delete the extension checkout; optionally drop the database state it claimed.
     *
     * @return array{droppedTables: list<string>, prunedLedger: int, deletedSettings: int, reloadLog: list<string>}
     */
    public function uninstall(string $folder, bool $dropTables): array
    {
        $this->assertInstalled($folder);

        $path = $this->extensionPath($folder);

        // Capture what the checkout claims before its files disappear.
        $migrationFiles = glob($path.'/*/Database/Migrations/*.php') ?: [];
        $claimedTables = DomainResidueScanner::tablesCreatedIn($migrationFiles);
        $ledgerEntries = array_map(fn (string $file): string => basename($file, '.php'), $migrationFiles);
        $settingKeys = $this->settingKeysInDatabase(
            DomainResidueScanner::settingKeysDeclaredIn(glob($path.'/*/Config/settings.php') ?: []),
        );

        if (! File::deleteDirectory($path)) {
            throw new InvalidArgumentException("Could not delete [$path]. Check filesystem permissions.");
        }

        if (! $dropTables) {
            return [
                'droppedTables' => [],
                'prunedLedger' => 0,
                'deletedSettings' => 0,
                'reloadLog' => $this->reloadRuntimeLog(),
            ];
        }

        $dropped = $this->scanner->dropTables($claimedTables);

        return [
            'droppedTables' => $dropped['dropped'],
            'prunedLedger' => $this->scanner->pruneLedger($ledgerEntries),
            'deletedSettings' => $this->scanner->deleteSettings($settingKeys),
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

    private function ownerFromRepo(string $repo): ?string
    {
        return preg_match('#github\.com[:/]([^/]+)/#', $repo, $matches) === 1 ? $matches[1] : null;
    }

    private function tokenForOwner(string $owner): ?string
    {
        $token = $this->settings->get(self::TOKEN_PREFIX.strtolower($owner));

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
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

    private function assertInstalled(string $folder): void
    {
        $this->assertFolderName($folder);

        if (! $this->isInstalled($folder)) {
            throw new InvalidArgumentException("Extension [$folder] is not installed.");
        }
    }

    private function assertFolderName(string $folder): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $folder) !== 1) {
            throw new InvalidArgumentException("Invalid extension folder [$folder].");
        }
    }

    private function extensionPath(string $folder): string
    {
        return base_path('extensions/'.$folder);
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
