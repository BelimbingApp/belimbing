<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services;

class KnowledgeNavigator
{
    /**
     * Curated BLB references Lara can use to navigate the framework knowledge base.
     *
     * @return list<array{title: string, path: string, summary: string, keywords: list<string>}>
     */
    public function catalog(): array
    {
        return [
            [
                'title' => 'BLB architecture overview',
                'path' => 'docs/architecture/overview.md',
                'summary' => 'High-level architecture, module layering, and framework boundaries.',
                'keywords' => ['architecture', 'overview', 'layers', 'framework'],
            ],
            [
                'title' => 'Lara system Agent',
                'path' => 'docs/architecture/ai/lara.md',
                'summary' => 'Lara identity, access model, session isolation, and orchestration scope.',
                'keywords' => ['lara', 'chat', 'system', 'orchestration', 'agent'],
            ],
            [
                'title' => 'Agent architecture',
                'path' => 'docs/architecture/ai-agent.md',
                'summary' => 'Runtime lifecycle, workspace model, fallback behavior, and agent architecture.',
                'keywords' => ['ai', 'agent', 'runtime', 'workspace', 'fallback'],
            ],
            [
                'title' => 'Authorization architecture',
                'path' => 'docs/architecture/authorization.md',
                'summary' => 'Capabilities, policy engine concepts, delegated actor rules, and access decisions.',
                'keywords' => ['auth', 'authorization', 'capability', 'policy', 'access'],
            ],
            [
                'title' => 'Database architecture',
                'path' => 'docs/architecture/database.md',
                'summary' => 'Module-aware migrations, seeding strategy, and data model conventions.',
                'keywords' => ['database', 'migration', 'seeder', 'schema'],
            ],
            [
                'title' => 'File structure',
                'path' => 'docs/architecture/file-structure.md',
                'summary' => 'Module-first placement rules and framework directory conventions.',
                'keywords' => ['file structure', 'module', 'placement', 'directory'],
            ],
            [
                'title' => 'Company module overview',
                'path' => 'docs/modules/company.md',
                'summary' => 'Company domain responsibilities, key entities, and UI workflows.',
                'keywords' => ['company', 'module', 'domain'],
            ],
            [
                'title' => 'User module overview',
                'path' => 'docs/modules/user.md',
                'summary' => 'User management boundaries, capabilities, and administration flows.',
                'keywords' => ['user', 'module', 'capability', 'admin'],
            ],
            [
                'title' => 'Employee module overview',
                'path' => 'docs/modules/employee.md',
                'summary' => 'Employee model, agent relations, and supervision chain.',
                'keywords' => ['employee', 'agent', 'supervisor', 'module'],
            ],
            [
                'title' => 'Project brief',
                'path' => 'docs/brief.md',
                'summary' => 'BLB principles, philosophy, and strategic direction.',
                'keywords' => ['brief', 'principles', 'vision', 'strategy'],
            ],
        ];
    }

    /**
     * Default references for prompt grounding when no query is provided.
     *
     * @return list<array{title: string, path: string, summary: string}>
     */
    public function defaultReferences(int $limit = 6): array
    {
        return array_map(
            fn (array $entry): array => $this->toReference($entry),
            array_slice($this->catalog(), 0, $limit)
        );
    }

    /**
     * Search curated BLB references by query keywords.
     *
     * @return list<array{title: string, path: string, summary: string}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $normalizedQuery = mb_strtolower(trim($query));
        if ($normalizedQuery === '') {
            return [];
        }

        $tokens = $this->queryTokens($normalizedQuery);
        $scored = [];

        foreach ($this->catalog() as $entry) {
            $score = $this->scoreEntry($entry, $normalizedQuery, $tokens);

            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'score' => $score,
                'reference' => $this->toReference($entry),
            ];
        }

        usort($scored, function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcmp($left['reference']['title'], $right['reference']['title']);
            }

            return $right['score'] <=> $left['score'];
        });

        return array_map(
            fn (array $item): array => $item['reference'],
            array_slice($scored, 0, $limit)
        );
    }

    /**
     * @param  array{title: string, path: string, summary: string, keywords: list<string>}  $entry
     * @return array{title: string, path: string, summary: string}
     */
    private function toReference(array $entry): array
    {
        return [
            'title' => $entry['title'],
            'path' => $entry['path'],
            'summary' => $entry['summary'],
        ];
    }

    /**
     * @return list<string>
     */
    private function queryTokens(string $normalizedQuery): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', $normalizedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array{title: string, path: string, summary: string, keywords: list<string>}  $entry
     * @param  list<string>  $tokens
     */
    private function scoreEntry(array $entry, string $normalizedQuery, array $tokens): int
    {
        $title = mb_strtolower($entry['title']);
        $summary = mb_strtolower($entry['summary']);
        $path = mb_strtolower($entry['path']);
        $keywords = mb_strtolower(implode(' ', $entry['keywords']));

        $score = 0;

        foreach ($tokens as $token) {
            if (str_contains($title, $token)) {
                $score += 5;
            }
            if (str_contains($summary, $token)) {
                $score += 3;
            }
            if (str_contains($path, $token)) {
                $score += 2;
            }
            if (str_contains($keywords, $token)) {
                $score += 4;
            }
        }

        if (str_contains($title, $normalizedQuery) || str_contains($summary, $normalizedQuery)) {
            $score += 8;
        }

        return $score;
    }
}
