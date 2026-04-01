<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Memory;

use App\Modules\Core\AI\DTO\MemoryChunk;

/**
 * Splits markdown content into chunks based on document structure.
 *
 * Chunking order of preference:
 * 1. Heading sections (## boundaries)
 * 2. Paragraph grouping within large sections
 * 3. Token-cap split as a final bound
 *
 * Preserves human meaning and supports useful citations.
 */
class MemoryChunker
{
    private readonly int $maxChunkChars;

    public function __construct()
    {
        $this->maxChunkChars = (int) config('ai.memory.max_chunk_chars', 2000);
    }

    /**
     * Chunk a markdown file into indexed sections.
     *
     * @param  string  $content  Full markdown content
     * @param  string  $sourceRelativePath  Relative path ( for metadata)
     * @param  string  $sourceHash  SHA-256 of the full source file
     * @return list<MemoryChunk>
     */
    public function chunk(string $content, string $sourceRelativePath, string $sourceHash): array
    {
        $sections = $this->splitByHeadings($content, $sourceRelativePath);
        $chunks = [];
        $order = 0;

        foreach ($sections as $section) {
            $sectionChunks = $this->splitSection($section, $sourceRelativePath, $sourceHash, $order);

            foreach ($sectionChunks as $chunk) {
                $chunks[] = $chunk;
                $order++;
            }
        }

        return $chunks;
    }

    /**
     * Split markdown content by ## headings.
     *
     * Content before the first heading uses the filename as heading.
     *
     * @return list<array{heading: string, body: string}>
     */
    private function splitByHeadings(string $content, string $sourcePath): array
    {
        $lines = explode("\n", $content);
        $sections = [];
        $currentHeading = pathinfo($sourcePath, PATHINFO_FILENAME);
        $currentBody = '';

        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+)/', $line, $matches)) {
                if (trim($currentBody) !== '') {
                    $sections[] = [
                        'heading' => $currentHeading,
                        'body' => trim($currentBody),
                    ];
                }

                $currentHeading = trim($matches[1]);
                $currentBody = '';
            } else {
                $currentBody .= $line."\n";
            }
        }

        if (trim($currentBody) !== '') {
            $sections[] = [
                'heading' => $currentHeading,
                'body' => trim($currentBody),
            ];
        }

        return $sections;
    }

    /**
     * Split a section into one or more chunks, respecting the size cap.
     *
     * @param  array{heading: string, body: string}  $section
     * @return list<MemoryChunk>
     */
    private function splitSection(array $section, string $sourcePath, string $sourceHash, int $startOrder): array
    {
        $body = $section['body'];
        $heading = $section['heading'];

        if (strlen($body) <= $this->maxChunkChars) {
            return [
                new MemoryChunk(
                    sourceRelativePath: $sourcePath,
                    sourceHash: $sourceHash,
                    heading: $heading,
                    content: $body,
                    fingerprint: hash('sha256', $body),
                    order: $startOrder,
                ),
            ];
        }

        return $this->splitByParagraphs($body, $heading, $sourcePath, $sourceHash, $startOrder);
    }

    /**
     * Split oversized section at paragraph boundaries.
     *
     * @return list<MemoryChunk>
     */
    private function splitByParagraphs(
        string $body,
        string $heading,
        string $sourcePath,
        string $sourceHash,
        int $startOrder,
    ): array {
        $paragraphs = preg_split('/\n{2,}/', $body);

        if ($paragraphs === false) {
            $paragraphs = [$body];
        }

        $chunks = [];
        $buffer = '';
        $order = $startOrder;
        $partIndex = 1;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($buffer !== '' && strlen($buffer) + strlen($paragraph) + 2 > $this->maxChunkChars) {
                $chunks[] = new MemoryChunk(
                    sourceRelativePath: $sourcePath,
                    sourceHash: $sourceHash,
                    heading: $heading.' (part '.$partIndex.')',
                    content: $buffer,
                    fingerprint: hash('sha256', $buffer),
                    order: $order,
                );
                $order++;
                $partIndex++;
                $buffer = '';
            }

            $buffer .= ($buffer !== '' ? "\n\n" : '').$paragraph;
        }

        if (trim($buffer) !== '') {
            $chunkHeading = $partIndex > 1 ? $heading.' (part '.$partIndex.')' : $heading;

            $chunks[] = new MemoryChunk(
                sourceRelativePath: $sourcePath,
                sourceHash: $sourceHash,
                heading: $chunkHeading,
                content: $buffer,
                fingerprint: hash('sha256', $buffer),
                order: $order,
            );
        }

        return $chunks;
    }
}
