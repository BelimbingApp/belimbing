<?php
namespace App\Base\Pdf\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

class SignedRenderTokenStore
{
    private const CACHE_PREFIX = 'blb:pdf:render-token:';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Issue a single-use render token. Returns the opaque token id.
     *
     * @param  array{view: string, data: array<string, mixed>, user_id: int|null, template_version: string, data_version: string}  $claims
     */
    public function issue(array $claims, int $ttlSeconds): string
    {
        $tokenId = (string) Str::ulid();
        $this->cache->put(self::CACHE_PREFIX.$tokenId, $claims, $ttlSeconds);

        return $tokenId;
    }

    /**
     * Atomically read and delete the claims for a token. Returns null if
     * the token is unknown, expired, or already consumed.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $tokenId): ?array
    {
        $key = self::CACHE_PREFIX.$tokenId;
        $claims = $this->cache->get($key);

        if ($claims === null) {
            return null;
        }

        $this->cache->forget($key);

        return $claims;
    }
}
