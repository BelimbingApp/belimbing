<?php

namespace App\Base\Foundation\ModuleManifest;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Discovers BLB plugins published under the BelimbingApp GitHub org.
 *
 * Per docs/plans/plugin-manager-ui.md:
 *  - Trusts exactly one source: the BelimbingApp org.
 *  - Anonymous GitHub API access (60 req/hr is comfortable for ~10 repos).
 *  - 24h-default cache in `base_foundation_plugin_catalog_cache`.
 *  - Read-only: no install, no code execution. The UI surfaces a copy-
 *    to-clipboard install command; operators run it from a shell.
 */
class BelimbingAppCatalogService
{
    public const SOURCE = 'github:BelimbingApp';

    private const ORG = 'BelimbingApp';

    private const TOPIC = 'blb-plugin';

    private const TABLE = 'base_foundation_plugin_catalog_cache';

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Default TTL in hours; honours config when set.
     */
    public function ttlHours(): int
    {
        $configured = (int) config('plugin_catalog.ttl_hours', 24);

        return $configured > 0 ? $configured : 24;
    }

    /**
     * @return list<PluginCatalogEntry>
     */
    public function available(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $rows = DB::table(self::TABLE)
            ->where('source', self::SOURCE)
            ->orderBy('repo_name')
            ->get();

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = $this->rowToEntry($row);
        }

        return $entries;
    }

    /**
     * Hit the GitHub API, replace the cache for this source.
     *
     * Returns the resulting list of entries. Catches per-repo failures
     * so a single malformed composer.json does not abort the whole sync.
     *
     * @return list<PluginCatalogEntry>
     */
    public function refresh(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $repos = $this->fetchRepoList();
        $now = now()->toIso8601String();

        DB::table(self::TABLE)->where('source', self::SOURCE)->delete();

        $entries = [];
        foreach ($repos as $repo) {
            try {
                $entry = $this->buildEntryForRepo($repo);
                if ($entry === null) {
                    continue;
                }

                DB::table(self::TABLE)->insert([
                    'source' => self::SOURCE,
                    'repo_name' => $entry->repoName,
                    'html_url' => $entry->htmlUrl,
                    'default_branch' => $entry->defaultBranch,
                    'default_branch_sha' => $entry->defaultBranchSha,
                    'composer_name' => $entry->composerName,
                    'module_identifier' => $entry->moduleIdentifier,
                    'role' => $entry->role,
                    'version' => $entry->version,
                    'description' => $entry->description,
                    'manifest' => json_encode($entry->manifest, JSON_THROW_ON_ERROR),
                    'fetched_at' => $entry->fetchedAt->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $entries[] = $entry;
            } catch (Throwable) {
                // Per-repo failure does not abort the rest of the sync.
                // A future enhancement might log the skipped repo.
                continue;
            }
        }

        return $entries;
    }

    public function lastFetchedAt(): ?DateTimeImmutable
    {
        if (! Schema::hasTable(self::TABLE)) {
            return null;
        }

        $row = DB::table(self::TABLE)
            ->where('source', self::SOURCE)
            ->orderByDesc('fetched_at')
            ->first(['fetched_at']);

        if ($row === null || $row->fetched_at === null) {
            return null;
        }

        return new DateTimeImmutable((string) $row->fetched_at);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRepoList(): array
    {
        $response = $this->http
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/orgs/'.self::ORG.'/repos', [
                'per_page' => 100,
                'type' => 'public',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $repos = $response->json();
        if (! is_array($repos)) {
            return [];
        }

        return array_values(array_filter(
            $repos,
            fn ($repo): bool => is_array($repo)
                && is_array($repo['topics'] ?? null)
                && in_array(self::TOPIC, $repo['topics'], true),
        ));
    }

    /**
     * @param  array<string, mixed>  $repo
     */
    private function buildEntryForRepo(array $repo): ?PluginCatalogEntry
    {
        $repoName = (string) ($repo['name'] ?? '');
        $htmlUrl = (string) ($repo['html_url'] ?? '');
        $defaultBranch = is_string($repo['default_branch'] ?? null) ? $repo['default_branch'] : null;

        if ($repoName === '' || $htmlUrl === '' || $defaultBranch === null) {
            return null;
        }

        $manifestData = $this->fetchComposerJson($repoName, $defaultBranch);
        if ($manifestData === null) {
            return null;
        }

        $blb = $manifestData['extra']['blb'] ?? null;
        if (! is_array($blb)) {
            return null;
        }

        $sha = $this->fetchDefaultBranchSha($repoName, $defaultBranch);

        return new PluginCatalogEntry(
            repoName: $repoName,
            htmlUrl: $htmlUrl,
            composerName: (string) ($manifestData['name'] ?? ''),
            moduleIdentifier: (string) ($blb['module'] ?? ''),
            role: (string) ($blb['role'] ?? 'unknown'),
            version: (string) ($blb['version'] ?? ''),
            description: (string) ($blb['description'] ?? ($manifestData['description'] ?? '')),
            defaultBranch: $defaultBranch,
            defaultBranchSha: $sha,
            fetchedAt: new DateTimeImmutable(),
            manifest: $blb,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchComposerJson(string $repoName, string $branch): ?array
    {
        $response = $this->http
            ->withHeaders(['Accept' => 'application/vnd.github.raw'])
            ->get('https://raw.githubusercontent.com/'.self::ORG."/{$repoName}/{$branch}/composer.json");

        if (! $response->successful()) {
            return null;
        }

        $data = json_decode($response->body(), true);

        return is_array($data) ? $data : null;
    }

    private function fetchDefaultBranchSha(string $repoName, string $branch): ?string
    {
        $response = $this->http
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get('https://api.github.com/repos/'.self::ORG."/{$repoName}/branches/{$branch}");

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();
        $sha = is_array($data) ? ($data['commit']['sha'] ?? null) : null;

        return is_string($sha) ? $sha : null;
    }

    private function rowToEntry(object $row): PluginCatalogEntry
    {
        $manifest = is_string($row->manifest)
            ? (json_decode($row->manifest, true) ?: [])
            : [];

        return new PluginCatalogEntry(
            repoName: (string) $row->repo_name,
            htmlUrl: (string) $row->html_url,
            composerName: (string) ($row->composer_name ?? ''),
            moduleIdentifier: (string) ($row->module_identifier ?? ''),
            role: (string) ($row->role ?? 'unknown'),
            version: (string) ($row->version ?? ''),
            description: (string) ($row->description ?? ''),
            defaultBranch: $row->default_branch === null ? null : (string) $row->default_branch,
            defaultBranchSha: $row->default_branch_sha === null ? null : (string) $row->default_branch_sha,
            fetchedAt: new DateTimeImmutable((string) $row->fetched_at),
            manifest: is_array($manifest) ? $manifest : [],
        );
    }
}
