<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Modules\Core\AI\DTO\MemoryChunk;
use App\Modules\Core\AI\DTO\MemoryIndexManifestEntry;
use PDO;

/**
 * Per-agent SQLite store for memory chunks and index manifest.
 *
 * The index is a rebuildable derivative of the canonical markdown sources.
 * Schema is created lazily on first access. All operations are scoped
 * to a single agent's database file.
 */
class MemoryIndexStore
{
    private ?PDO $pdo = null;

    /**
     * @param  string  $databasePath  Absolute path to the SQLite database file
     */
    public function __construct(
        private readonly string $databasePath,
    ) {}

    /**
     * Create a store for the given agent.
     */
    public static function forAgent(int $employeeId): self
    {
        $path = rtrim((string) config('ai.workspace_path'), '/').'/'.$employeeId.'/memory.sqlite';

        return new self($path);
    }

    /**
     * Ensure the database and schema exist.
     */
    public function ensureSchema(): void
    {
        $pdo = $this->connection();

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS memory_chunks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_path TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                heading TEXT NOT NULL,
                content TEXT NOT NULL,
                fingerprint TEXT NOT NULL,
                chunk_order INTEGER NOT NULL,
                indexed_at INTEGER NOT NULL
            )
        ');

        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_chunks_source ON memory_chunks (source_path)
        ');

        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_chunks_fingerprint ON memory_chunks (fingerprint)
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS memory_manifest (
                source_path TEXT PRIMARY KEY,
                content_hash TEXT NOT NULL,
                chunk_count INTEGER NOT NULL,
                indexed_at INTEGER NOT NULL
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS memory_meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ');
    }

    /**
     * Check whether this index database exists on disk.
     */
    public function exists(): bool
    {
        return is_file($this->databasePath);
    }

    /**
     * Remove all chunks for a given source file.
     */
    public function deleteChunksForSource(string $sourceRelativePath): void
    {
        $pdo = $this->connection();
        $stmt = $pdo->prepare('DELETE FROM memory_chunks WHERE source_path = :path');
        $stmt->execute(['path' => $sourceRelativePath]);
    }

    /**
     * Insert chunks for a source file (within a transaction).
     *
     * @param  list<MemoryChunk>  $chunks
     */
    public function insertChunks(array $chunks): void
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare('
            INSERT INTO memory_chunks (source_path, source_hash, heading, content, fingerprint, chunk_order, indexed_at)
            VALUES (:source_path, :source_hash, :heading, :content, :fingerprint, :chunk_order, :indexed_at)
        ');

        $now = time();

        foreach ($chunks as $chunk) {
            $stmt->execute([
                'source_path' => $chunk->sourceRelativePath,
                'source_hash' => $chunk->sourceHash,
                'heading' => $chunk->heading,
                'content' => $chunk->content,
                'fingerprint' => $chunk->fingerprint,
                'chunk_order' => $chunk->order,
                'indexed_at' => $now,
            ]);
        }
    }

    /**
     * Update the manifest entry for a source file.
     */
    public function upsertManifestEntry(MemoryIndexManifestEntry $entry): void
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare('
            INSERT INTO memory_manifest (source_path, content_hash, chunk_count, indexed_at)
            VALUES (:path, :hash, :count, :at)
            ON CONFLICT (source_path) DO UPDATE SET
                content_hash = excluded.content_hash,
                chunk_count = excluded.chunk_count,
                indexed_at = excluded.indexed_at
        ');

        $stmt->execute([
            'path' => $entry->relativePath,
            'hash' => $entry->contentHash,
            'count' => $entry->chunkCount,
            'at' => $entry->indexedAt,
        ]);
    }

    /**
     * Get the manifest entry for a source file.
     */
    public function manifestEntry(string $sourceRelativePath): ?MemoryIndexManifestEntry
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare('SELECT * FROM memory_manifest WHERE source_path = :path');
        $stmt->execute(['path' => $sourceRelativePath]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new MemoryIndexManifestEntry(
            relativePath: $row['source_path'],
            contentHash: $row['content_hash'],
            chunkCount: (int) $row['chunk_count'],
            indexedAt: (int) $row['indexed_at'],
        );
    }

    /**
     * Get all manifest entries.
     *
     * @return list<MemoryIndexManifestEntry>
     */
    public function allManifestEntries(): array
    {
        $pdo = $this->connection();

        $stmt = $pdo->query('SELECT * FROM memory_manifest ORDER BY source_path');
        $entries = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = new MemoryIndexManifestEntry(
                relativePath: $row['source_path'],
                contentHash: $row['content_hash'],
                chunkCount: (int) $row['chunk_count'],
                indexedAt: (int) $row['indexed_at'],
            );
        }

        return $entries;
    }

    /**
     * Remove manifest entry and chunks for sources that no longer exist.
     *
     * @param  list<string>  $currentSourcePaths  Paths that still exist
     * @return int Number of stale sources removed
     */
    public function removeStaleEntries(array $currentSourcePaths): int
    {
        $pdo = $this->connection();

        $existing = $pdo->query('SELECT source_path FROM memory_manifest')->fetchAll(PDO::FETCH_COLUMN);
        $stale = array_diff($existing, $currentSourcePaths);

        if ($stale === []) {
            return 0;
        }

        foreach ($stale as $path) {
            $this->deleteChunksForSource($path);

            $stmt = $pdo->prepare('DELETE FROM memory_manifest WHERE source_path = :path');
            $stmt->execute(['path' => $path]);
        }

        return count($stale);
    }

    /**
     * Perform keyword search across all chunks.
     *
     * @param  list<string>  $tokens  Lowercase search tokens
     * @param  int  $limit  Maximum results
     * @return list<array{source_path: string, heading: string, content: string, score: int}>
     */
    public function keywordSearch(array $tokens, int $limit): array
    {
        if ($tokens === []) {
            return [];
        }

        $pdo = $this->connection();

        // Fetch all chunks and score in PHP — SQLite FTS5 would be
        // better at scale, but for the workspace-sized corpus this is
        // simpler and avoids an FTS extension dependency.
        $stmt = $pdo->query('SELECT source_path, heading, content FROM memory_chunks ORDER BY chunk_order');
        $scored = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $score = $this->scoreChunk($row, $tokens);

            if ($score > 0) {
                $scored[] = [
                    'source_path' => $row['source_path'],
                    'heading' => $row['heading'],
                    'content' => $row['content'],
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Total chunk count in the index.
     */
    public function chunkCount(): int
    {
        $pdo = $this->connection();

        return (int) $pdo->query('SELECT COUNT(*) FROM memory_chunks')->fetchColumn();
    }

    /**
     * Get a metadata value.
     */
    public function getMeta(string $key): ?string
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare('SELECT value FROM memory_meta WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    /**
     * Set a metadata value.
     */
    public function setMeta(string $key, string $value): void
    {
        $pdo = $this->connection();

        $stmt = $pdo->prepare('
            INSERT INTO memory_meta (key, value) VALUES (:key, :value)
            ON CONFLICT (key) DO UPDATE SET value = excluded.value
        ');

        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    /**
     * Commit the active transaction.
     */
    public function commit(): void
    {
        $this->connection()->commit();
    }

    /**
     * Roll back the active transaction.
     */
    public function rollBack(): void
    {
        $this->connection()->rollBack();
    }

    /**
     * Get or create the PDO connection with lazy schema init.
     */
    private function connection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dir = dirname($this->databasePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:'.$this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Enable WAL for concurrent reads during indexing
        $this->pdo->exec('PRAGMA journal_mode=WAL');

        return $this->pdo;
    }

    /**
     * Score a chunk row against search tokens.
     *
     * Heading matches weighted 3×, content matches weighted 1×.
     *
     * @param  array{heading: string, content: string}  $row
     * @param  list<string>  $tokens
     */
    private function scoreChunk(array $row, array $tokens): int
    {
        $heading = mb_strtolower($row['heading']);
        $content = mb_strtolower($row['content']);
        $score = 0;

        foreach ($tokens as $token) {
            if (str_contains($heading, $token)) {
                $score += 3;
            }

            if (str_contains($content, $token)) {
                $score += 1;
            }
        }

        return $score;
    }
}
