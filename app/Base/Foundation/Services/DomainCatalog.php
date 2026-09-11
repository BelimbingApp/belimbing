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
 * An empty catalog and one that could not be looked up are different facts and
 * are reported as different facts: {@see complete()} and {@see problems()}.
 */
class DomainCatalog
{
    public const TOPIC = 'blb-domain';

    private const CACHE_KEY = 'base.foundation.domain-catalog';

    private const REPO_PREFIX = 'blb-';

    private const PER_PAGE = 100;

    /** A guard against a pagination loop, not an expected ceiling. */
    private const MAX_PAGES = 20;

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
     * False when any selected owner could not be asked. One owner answering
     * must not vouch for another that failed: "nothing is published there" and
     * "we could not ask" are different facts, and a fallback owner's Domains
     * must not quietly stand in for the ones an earlier owner would have
     * supplied (#944 review).
     */
    public function complete(): bool
    {
        return $this->cached()['complete'];
    }

    /**
     * One line per owner that could not be asked, or that GitHub says does not
     * exist. Empty when every owner answered.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->cached()['problems'];
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
     * The GitHub.com owner of a remote URL in either SSH or HTTPS spelling.
     *
     * The host must be github.com exactly. `github.internal.example` is a
     * different service: treating its path segment as an owner and then asking
     * api.github.com about it would offer public repositories belonging to
     * someone the checkout has no relationship with, as installable executable
     * code (#944 review). An enterprise or self-hosted remote is skipped, never
     * translated; `domains.owners` is how such an installation says who owns
     * its Domains.
     */
    public static function ownerFromRemoteUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (preg_match('#^[^@/]+@([^:/]+):([^/]+)/#', $url, $ssh) === 1) {
            return self::isGitHubHost($ssh[1]) ? $ssh[2] : null;
        }
        if (preg_match('#^[a-z+]+://(?:[^@/]+@)?([^/]+)/([^/]+)/#', $url.'/', $https) === 1) {
            return self::isGitHubHost($https[1]) ? $https[2] : null;
        }

        return null;
    }

    /** github.com itself, with an optional port; never a host that resembles it. */
    private static function isGitHubHost(string $host): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));

        return $host === 'github.com' || $host === 'www.github.com';
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
     * @return array{entries: array<string, array{repo: string, description: string, owner: string}>, complete: bool, problems: list<string>}
     */
    private function cached(): array
    {
        /** @var array{entries: array<string, array{repo: string, description: string, owner: string}>, complete: bool, problems: list<string>} */
        return Cache::remember(self::CACHE_KEY, $this->ttlSeconds(), fn (): array => $this->fetch());
    }

    /**
     * @return array{entries: array<string, array{repo: string, description: string, owner: string}>, complete: bool, problems: list<string>}
     */
    private function fetch(): array
    {
        $owners = $this->owners();
        if ($owners === []) {
            // No remote and no configured owner: nothing to ask. Calling that
            // a failed lookup would blame the network for a local condition.
            return ['entries' => [], 'complete' => true, 'problems' => []];
        }

        $entries = [];
        $problems = [];

        foreach ($owners as $owner) {
            $result = $this->fetchRepos($owner);
            if ($result['status'] !== 'ok') {
                $problems[] = $result['problem'];

                continue;
            }

            foreach ($result['repos'] as $repo) {
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

        return ['entries' => $entries, 'complete' => $problems === [], 'problems' => $problems];
    }

    /**
     * Every repository an owner publishes, following pagination to the end.
     *
     * `per_page=100` is a page size, not an inventory: a Domain published on a
     * later page would be omitted, and the omission cached for a day (#944
     * review). The owner may be an organisation or a personal account, and the
     * two list from different endpoints - a user-owned fork asking /orgs gets
     * 404 and would otherwise show its upstream's Domains instead of its own.
     *
     * @return array{status: string, repos: list<array<string, mixed>>, problem: string}
     */
    private function fetchRepos(string $owner): array
    {
        $url = 'https://api.github.com/orgs/'.rawurlencode($owner).'/repos';
        $triedUsers = false;
        $repos = [];
        $page = 0;

        while ($page < self::MAX_PAGES) {
            try {
                $response = $this->http
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    // Pass no query array on later pages: an empty one replaces
                    // the page marker the Link header put in the URL.
                    ->get($url, $page === 0 ? ['per_page' => self::PER_PAGE, 'type' => 'public'] : null);
            } catch (Throwable $exception) {
                return ['status' => 'failed', 'repos' => [], 'problem' => $owner.': '.$exception->getMessage()];
            }

            if ($response->status() === 404 && ! $triedUsers) {
                // Not an organisation; a personal account lists elsewhere.
                $triedUsers = true;
                $url = 'https://api.github.com/users/'.rawurlencode($owner).'/repos';
                $page = 0;

                continue;
            }

            if ($response->status() === 404) {
                return ['status' => 'missing', 'repos' => [], 'problem' => $owner.': no such GitHub organisation or user'];
            }

            if (! $response->successful()) {
                return ['status' => 'failed', 'repos' => [], 'problem' => $owner.': GitHub answered HTTP '.$response->status()];
            }

            $body = $response->json();
            if (! is_array($body)) {
                return ['status' => 'failed', 'repos' => [], 'problem' => $owner.': GitHub did not return a repository list'];
            }

            foreach ($body as $repo) {
                if (is_array($repo)) {
                    $repos[] = $repo;
                }
            }

            $next = self::nextPageUrl((string) $response->header('Link'));
            if ($next === null) {
                return ['status' => 'ok', 'repos' => $repos, 'problem' => ''];
            }

            $url = $next;
            $page++;
        }

        // Refuse a truncated inventory rather than cache it as the whole thing.
        return ['status' => 'failed', 'repos' => [], 'problem' => $owner.': more than '.self::MAX_PAGES.' pages of repositories; refusing a partial list'];
    }

    /**
     * The `rel="next"` URL of a GitHub Link header, or null on the last page.
     */
    private static function nextPageUrl(string $link): ?string
    {
        if (trim($link) === '') {
            return null;
        }

        foreach (explode(',', $link) as $part) {
            if (preg_match('/<([^>]+)>\s*;\s*rel="next"/i', $part, $match) === 1) {
                return $match[1];
            }
        }

        return null;
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
