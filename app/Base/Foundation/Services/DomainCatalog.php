<?php

namespace App\Base\Foundation\Services;

use App\Base\Support\Git\GitRepositoryConfigReader;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Discovers installable Domains instead of shipping a list of them (#941).
 *
 * A Domain repository announces itself two ways, both conventions and neither
 * written down here: it is named `blb-<id>`, and it carries the GitHub topic
 * `blb-domain`. The id is everything else — `blb-people-connector` mounts at
 * `app/Domains/PeopleConnector` — so publishing a Domain that follows the
 * shape needs no platform change. See app/Domains/AGENTS.md.
 *
 * The organisation is not written down either. It comes from this checkout's
 * own git remotes, `origin` first and then `upstream`, so a fork discovers its
 * own Domains and falls back to the repository it forked. `domains.owners`
 * overrides both for an installation whose checkout has no usable remote.
 *
 * Trust boundary, stated where the decision lives: a topic is self-applied, so
 * "published in an organisation this checkout already points at" is what does
 * the authorising. That is acceptable for first-party Domains. It is not a
 * model for third-party Extensions, which need signing or an allowlist.
 *
 * Anonymous GitHub API access; the result is cached, because an unreachable or
 * rate-limited GitHub must not make the install screen hang on every render.
 * An empty catalog and an unreachable one are different facts and are reported
 * as different facts: {@see reachable()}.
 */
class DomainCatalog
{
    public const TOPIC = 'blb-domain';

    private const CACHE_KEY = 'base.foundation.domain-catalog';

    private const REPO_PREFIX = 'blb-';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Installable Domains keyed by mount name (`PeopleConnector`).
     *
     * @return array<string, array{repo: string, description: string, owner: string}>
     */
    public function entries(): array
    {
        return $this->cached()['entries'];
    }

    /**
     * False when the last lookup could not reach GitHub. The caller must be
     * able to tell "nothing is published" from "we could not ask".
     */
    public function reachable(): bool
    {
        return $this->cached()['reachable'];
    }

    /**
     * Owners to search, in order: `origin`, then `upstream`. Configuration
     * wins outright, for a checkout with no remotes at all.
     *
     * @return list<string>
     */
    public function owners(): array
    {
        $configured = config('domains.owners');
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }
        if (is_array($configured)) {
            $owners = array_values(array_filter(array_map(
                static fn ($owner): string => is_string($owner) ? trim($owner) : '',
                $configured,
            )));
            if ($owners !== []) {
                return array_values(array_unique($owners));
            }
        }

        $reader = new GitRepositoryConfigReader(base_path());
        $owners = [];
        foreach (['origin', 'upstream'] as $remote) {
            $owner = self::ownerFromRemoteUrl((string) $reader->remoteUrl($remote));
            if ($owner !== null && ! in_array($owner, $owners, true)) {
                $owners[] = $owner;
            }
        }

        return $owners;
    }

    /**
     * The GitHub owner of a remote URL in either SSH or HTTPS spelling; null
     * when it is not a recognisable GitHub remote, so a self-hosted or
     * non-GitHub remote is skipped rather than guessed at.
     */
    public static function ownerFromRemoteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[^@]+@([^:]+):([^/]+)/#', $url, $ssh) === 1) {
            return str_contains($ssh[1], 'github.') ? $ssh[2] : null;
        }
        if (preg_match('#^[a-z+]+://(?:[^@/]+@)?([^/]+)/([^/]+)/#', $url.'/', $https) === 1) {
            return str_contains($https[1], 'github.') ? $https[2] : null;
        }

        return null;
    }

    /**
     * `blb-people-connector` -> `PeopleConnector`; null for a repository name
     * that is not a Domain repository name. The inverse of the rule
     * scripts/ci/domain-registry.php applies.
     */
    public static function mountNameFor(string $repoName): ?string
    {
        if (! str_starts_with($repoName, self::REPO_PREFIX)) {
            return null;
        }

        $id = substr($repoName, strlen(self::REPO_PREFIX));
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id) !== 1) {
            return null;
        }

        return implode('', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $id),
        ));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function ttlSeconds(): int
    {
        $hours = (int) config('domains.catalog_ttl_hours', 24);

        return ($hours > 0 ? $hours : 24) * 3600;
    }

    /**
     * @return array{entries: array<string, array{repo: string, description: string, owner: string}>, reachable: bool}
     */
    private function cached(): array
    {
        /** @var array{entries: array<string, array{repo: string, description: string, owner: string}>, reachable: bool} */
        return Cache::remember(self::CACHE_KEY, $this->ttlSeconds(), fn (): array => $this->fetch());
    }

    /**
     * @return array{entries: array<string, array{repo: string, description: string, owner: string}>, reachable: bool}
     */
    private function fetch(): array
    {
        $owners = $this->owners();
        if ($owners === []) {
            // No remote and no configured owner: nothing to ask, and saying
            // "unreachable" would blame the network for a local condition.
            return ['entries' => [], 'reachable' => true];
        }

        $entries = [];
        $reachable = false;

        foreach ($owners as $owner) {
            $repos = $this->fetchRepos($owner);
            if ($repos === null) {
                continue;
            }

            $reachable = true;
            foreach ($repos as $repo) {
                $entry = $this->toEntry($owner, $repo);
                if ($entry === null) {
                    continue;
                }
                // Earlier owners win: origin before upstream, so a fork's own
                // Domain is preferred over the one it forked.
                $entries[$entry['name']] ??= $entry['entry'];
            }
        }

        ksort($entries);

        return ['entries' => $entries, 'reachable' => $reachable];
    }

    /**
     * @return list<array<string, mixed>>|null null when GitHub could not be reached
     */
    private function fetchRepos(string $owner): ?array
    {
        try {
            $response = $this->http
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/orgs/'.$owner.'/repos', [
                    'per_page' => 100,
                    'type' => 'public',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $repos = $response->json();

        return is_array($repos) ? array_values(array_filter($repos, 'is_array')) : null;
    }

    /**
     * @param  array<string, mixed>  $repo
     * @return array{name: string, entry: array{repo: string, description: string, owner: string}}|null
     */
    private function toEntry(string $owner, array $repo): ?array
    {
        $topics = $repo['topics'] ?? null;
        if (! is_array($topics) || ! in_array(self::TOPIC, $topics, true)) {
            return null;
        }

        $repoName = is_string($repo['name'] ?? null) ? $repo['name'] : '';
        $name = self::mountNameFor($repoName);
        if ($name === null) {
            return null;
        }

        $cloneUrl = is_string($repo['clone_url'] ?? null) && $repo['clone_url'] !== ''
            ? $repo['clone_url']
            : 'https://github.com/'.$owner.'/'.$repoName.'.git';

        return [
            'name' => $name,
            'entry' => [
                'repo' => $cloneUrl,
                'description' => is_string($repo['description'] ?? null) ? $repo['description'] : '',
                'owner' => $owner,
            ],
        ];
    }
}
