<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Menu\Services\VisibleNavMenuItemsFlat;

/**
 * Lists navigable sidebar menu entries for the authenticated user.
 *
 * Backed by {@see NavigableMenuSnapshot} (default: {@see VisibleNavMenuItemsFlat}).
 */
final class VisibleNavMenuSnapshotTool extends AbstractTool
{
    use ProvidesToolMetadata;

    /**
     * Maximum entries returned in one call (post-filter) to bound prompt size.
     */
    private const MAX_ITEMS = 200;

    public function __construct(
        private readonly NavigableMenuSnapshot $navigableMenuSnapshot,
    ) {}

    public function name(): string
    {
        return 'visible_nav_menu';
    }

    public function description(): string
    {
        return 'List Belimbing sidebar navigation entries visible to the current user (label, relative path, route name). '
            .'Use this before the navigate tool when you need valid internal paths instead of guessing. '
            .'Optional filter matches label or path (case-insensitive substring).';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'filter',
                'Optional case-insensitive substring matched against menu label or path (e.g. "postcode", "employee"). '
                    .'Omit to return the full visible list (capped).',
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::CONTEXT;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    /**
     * Returns null — no dedicated capability key (YAGNI: same data as the UI menu).
     *
     * The snapshot mirrors sidebar entries already filtered for this user via
     * the menu access checker; {@see handle()} still rejects unauthenticated calls.
     */
    public function requiredCapability(): ?string
    {
        return null;
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Visible navigation menu',
            'summary' => 'Read the navigable menu entries the current user can see in Belimbing.',
            'explanation' => 'Returns the same navigable routes as the sidebar, filtered by authorization. '
                .'Paths are normalized to a leading "/" for use with the navigate tool. '
                .'Large menus are truncated with a flag in the payload.',
            'limits' => [
                'Internal Belimbing navigation only',
                (string) self::MAX_ITEMS.' items maximum per response',
            ],
            'test_examples' => [
                [
                    'label' => 'Full snapshot',
                    'input' => [],
                ],
                [
                    'label' => 'Filter by label',
                    'input' => ['filter' => 'user'],
                ],
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $user = auth()->user();

        if ($user === null) {
            return ToolResult::error('No authenticated user for this session.');
        }

        $filter = $this->optionalString($arguments, 'filter');
        $flat = $this->navigableMenuSnapshot->snapshotForUser($user)['flat'];
        $rows = $this->buildRows($flat, $filter);

        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['label'], $b['label']),
        );

        $total = count($rows);
        $truncated = $total > self::MAX_ITEMS;

        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ITEMS);
        }

        $payload = [
            'items' => $rows,
            'total_matched' => $total,
            'returned' => count($rows),
            'truncated' => $truncated,
        ];

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if (! is_string($encoded)) {
            return ToolResult::error('Failed to encode navigation menu snapshot.');
        }

        return ToolResult::success($encoded);
    }

    /**
     * @param  array<string|int, array<string, mixed>>  $flat
     * @return list<array{menu_id: string, label: string, path: string, route: ?string}>
     */
    private function buildRows(array $flat, ?string $filter): array
    {
        $rows = [];
        $needle = $filter !== null ? strtolower($filter) : null;

        foreach ($flat as $menuId => $row) {
            $normalizedRow = $this->normalizedRow($menuId, $row, $needle);

            if ($normalizedRow !== null) {
                $rows[] = $normalizedRow;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{menu_id: string, label: string, path: string, route: ?string}|null
     */
    private function normalizedRow(string|int $menuId, array $row, ?string $needle): ?array
    {
        $path = $this->relativePathFromHref(isset($row['href']) && is_string($row['href']) ? $row['href'] : null);

        if ($path === null || ! str_starts_with($path, '/')) {
            return null;
        }

        $label = is_string($row['label'] ?? null) ? $row['label'] : '';

        if ($needle !== null && ! str_contains(strtolower($label.' '.$path), $needle)) {
            return null;
        }

        return [
            'menu_id' => is_string($menuId) ? $menuId : (string) $menuId,
            'label' => $label,
            'path' => $path,
            'route' => isset($row['route']) && is_string($row['route']) ? $row['route'] : null,
        ];
    }

    private function relativePathFromHref(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $trimmed = trim($href);

        if ($trimmed === '') {
            return null;
        }

        $path = parse_url($trimmed, PHP_URL_PATH);

        return is_string($path) && $path !== ''
            ? $path
            : (str_starts_with($trimmed, '/') ? $trimmed : null);
    }
}
