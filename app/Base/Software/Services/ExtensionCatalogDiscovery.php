<?php

namespace App\Base\Software\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Discovers installable private Extension repositories from trusted GitHub owners.
 *
 * Trust is scoped deliberately: only owners that already hold a token under
 * GitHub Access (`integrations.github.token.{owner}`) are queried, and only
 * repositories the owner explicitly marked with the `belimbing-extension`
 * topic are offered. There is still no free-text URL install path — discovery
 * fills the list the operator picks from; `config/extensions.php` remains a
 * pin/override layer that wins on key collisions (see ExtensionInstaller).
 *
 * Listing goes through `GET /orgs/{owner}/repos` (org owners, includes private
 * repos the token can see) and falls back to `GET /user/repos` filtered to the
 * owner login (user owners — the public-only `/users/{owner}/repos` endpoint
 * would hide exactly the private repos this feature exists for). First page
 * only (100 repos per owner). Results are cached briefly per owner; one
 * owner's API failure is collected as an error note and never hides the rest.
 */
class ExtensionCatalogDiscovery
{
    public const TOPIC = 'belimbing-extension';

    private const API = 'https://api.github.com';

    private const CACHE_KEY_PREFIX = 'software.extension-discovery.';

    private const CACHE_SECONDS = 60;

    public function __construct(
        private readonly GitHubTokenStore $tokens,
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Discovered install candidates keyed by their derived PascalCase
     * `app/Extensions/{Extension}` folder, plus per-owner failure notes.
     *
     * Repo names are kebab/underscore on GitHub; the folder key is the
     * PascalCase form (`blb-sbg` → `BlbSbg`). Names that cannot produce a
     * valid folder (e.g. leading digit) are skipped.
     *
     * @return array{
     *     candidates: array<string, array{repo: string, description: string, owner: string, has_token: bool}>,
     *     errors: array<string, string>,
     * }
     */
    public function discover(): array
    {
        $candidates = [];
        $errors = [];

        foreach ($this->tokens->owners() as $owner) {
            $result = $this->cache->remember(
                self::CACHE_KEY_PREFIX.$owner,
                self::CACHE_SECONDS,
                fn (): array => $this->fetchOwner($owner),
            );

            if ($result['error'] !== null) {
                $errors[$owner] = $result['error'];
            }

            foreach ($result['repos'] as $repo) {
                $folder = $this->folderFor((string) ($repo['name'] ?? ''));

                if ($folder === null || isset($candidates[$folder])) {
                    continue;
                }

                $candidates[$folder] = [
                    'repo' => (string) ($repo['html_url'] ?? ''),
                    'description' => (string) ($repo['description'] ?? ''),
                    'owner' => $owner,
                    'has_token' => true,
                ];
            }
        }

        ksort($candidates);

        return ['candidates' => $candidates, 'errors' => $errors];
    }

    /**
     * One discovered candidate by folder key, or null. Server-side resolution
     * for the install flow: the UI only ever submits the folder key, never a URL.
     *
     * @return array{repo: string, description: string, owner: string, has_token: bool}|null
     */
    public function candidate(string $folder): ?array
    {
        return $this->discover()['candidates'][$folder] ?? null;
    }

    /**
     * @return array{repos: list<array<string, mixed>>, error: string|null}
     */
    private function fetchOwner(string $owner): array
    {
        try {
            $response = $this->get('/orgs/'.$owner.'/repos', $owner);

            if ($response->status() === 404) {
                // Not an org: list the token's visible repos and keep the owner's.
                $response = $this->get('/user/repos', $owner);

                if ($response->successful()) {
                    return ['repos' => $this->marked($response->json(), $owner), 'error' => null];
                }
            } elseif ($response->successful()) {
                return ['repos' => $this->marked($response->json()), 'error' => null];
            }

            return [
                'repos' => [],
                'error' => (string) __('GitHub returned HTTP :status while listing repositories.', ['status' => $response->status()]),
            ];
        } catch (Throwable $exception) {
            return ['repos' => [], 'error' => $exception->getMessage()];
        }
    }

    private function get(string $path, string $owner): Response
    {
        return $this->http
            ->withToken((string) $this->tokens->tokenFor($owner))
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get(self::API.$path, ['per_page' => 100]);
    }

    /**
     * Keep only repos carrying the opt-in topic (and, when given, the owner's own).
     *
     * @return list<array<string, mixed>>
     */
    private function marked(mixed $repos, ?string $owner = null): array
    {
        if (! is_array($repos)) {
            return [];
        }

        return array_values(array_filter($repos, function ($repo) use ($owner): bool {
            if (! is_array($repo) || ! is_array($repo['topics'] ?? null) || ! in_array(self::TOPIC, $repo['topics'], true)) {
                return false;
            }

            return $owner === null
                || strcasecmp((string) ($repo['owner']['login'] ?? ''), $owner) === 0;
        }));
    }

    /**
     * PascalCase `app/Extensions/{Extension}` folder for a GitHub repo name.
     */
    private function folderFor(string $name): ?string
    {
        $folder = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));

        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $folder) === 1 ? $folder : null;
    }
}
