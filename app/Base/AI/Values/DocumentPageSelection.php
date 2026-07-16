<?php

namespace App\Base\AI\Values;

use InvalidArgumentException;

/**
 * Validated, bounded PDF page ranges suitable for pdftotext arguments.
 */
final readonly class DocumentPageSelection
{
    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private function __construct(
        public array $ranges,
        public string $label,
        public bool $explicit,
    ) {}

    public static function parse(
        ?string $pages,
        int $maxSelectedPages,
        int $maxPageNumber,
        int $maxSegments,
    ): self {
        $maxSelectedPages = max(1, $maxSelectedPages);
        $maxPageNumber = max(1, $maxPageNumber);
        $maxSegments = max(1, $maxSegments);
        $pages = is_string($pages) ? trim($pages) : '';

        if ($pages === '') {
            $lastPage = min($maxSelectedPages, $maxPageNumber);

            return new self(
                ranges: [[1, $lastPage]],
                label: $lastPage === 1 ? '1' : '1-'.$lastPage,
                explicit: false,
            );
        }

        if (mb_strlen($pages) > 100) {
            throw new InvalidArgumentException('"pages" must not exceed 100 characters.');
        }

        if (! preg_match('/^\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*$/', $pages)) {
            throw new InvalidArgumentException(
                'Invalid "pages" format. Expected values such as "1-5", "1,3,7", or "1-3,5,8-10".'
            );
        }

        $segments = explode(',', $pages);

        if (count($segments) > $maxSegments) {
            throw new InvalidArgumentException(
                '"pages" contains too many ranges; the maximum is '.$maxSegments.'.'
            );
        }

        $ranges = [];

        foreach ($segments as $segment) {
            $range = self::parseSegment($segment);
            [$first, $last] = $range;

            if ($first < 1 || $last < $first) {
                throw new InvalidArgumentException('Each page range must be ascending and start at page 1 or later.');
            }

            if ($last > $maxPageNumber) {
                throw new InvalidArgumentException(
                    'Page numbers must not exceed '.$maxPageNumber.'.'
                );
            }

            $ranges[] = $range;
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $ranges = self::mergeRanges($ranges);
        $selectedPages = array_sum(array_map(
            static fn (array $range): int => $range[1] - $range[0] + 1,
            $ranges,
        ));

        if ($selectedPages > $maxSelectedPages) {
            throw new InvalidArgumentException(
                '"pages" selects '.$selectedPages.' pages; the maximum is '.$maxSelectedPages.'.'
            );
        }

        return new self(
            ranges: $ranges,
            label: implode(',', array_map(self::rangeLabel(...), $ranges)),
            explicit: true,
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseSegment(string $segment): array
    {
        $bounds = explode('-', $segment, 2);
        $first = (int) $bounds[0];
        $last = isset($bounds[1]) ? (int) $bounds[1] : $first;

        return [$first, $last];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    private static function mergeRanges(array $ranges): array
    {
        $merged = [];

        foreach ($ranges as $range) {
            $lastIndex = array_key_last($merged);

            if ($lastIndex === null || $range[0] > $merged[$lastIndex][1] + 1) {
                $merged[] = $range;

                continue;
            }

            $merged[$lastIndex][1] = max($merged[$lastIndex][1], $range[1]);
        }

        return $merged;
    }

    /**
     * @param  array{0: int, 1: int}  $range
     */
    private static function rangeLabel(array $range): string
    {
        return $range[0] === $range[1]
            ? (string) $range[0]
            : $range[0].'-'.$range[1];
    }
}
