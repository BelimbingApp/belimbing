<?php

namespace App\Base\Foundation\Services;

/**
 * Stable plain-text rendering for Module inspection command reports.
 */
final class ModuleCheckReportRenderer
{
    private const EMPTY_REPORT_LINE = '  (none)';

    /**
     * @param  array{
     *     module: string,
     *     composition: list<string>,
     *     refusals: list<string>,
     *     routes: list<string>,
     *     tables: list<string>,
     *     bindings: list<array{abstract: string, resolved: bool}>,
     *     capabilities: array{accepted: list<string>, rejected: array<string, string>},
     *     ok: bool
     * }  $report
     */
    public function render(array $report): string
    {
        $lines = ['module: '.$report['module']];
        $lines = [...$lines, ...$this->reportSection('composition:', $report['composition'])];
        $lines = [...$lines, ...$this->reportSection('refusals:', $report['refusals'])];
        $lines = [...$lines, ...$this->reportSection('routes:', $report['routes'])];
        $lines = [...$lines, ...$this->reportSection('tables:', $report['tables'])];
        $lines = [...$lines, ...$this->reportSection(
            'bindings:',
            array_map(
                static fn (array $binding): string => $binding['abstract'].' ['.($binding['resolved'] ? 'resolved' : 'missing').']',
                $report['bindings'],
            ),
        )];

        if ($report['capabilities']['accepted'] !== [] || $report['capabilities']['rejected'] !== []) {
            $lines[] = 'Capabilities:';
            $lines[] = '  accepted: '.count($report['capabilities']['accepted']);
            $lines[] = '  rejected: '.count($report['capabilities']['rejected']);
            foreach ($report['capabilities']['rejected'] as $capability => $reason) {
                $lines[] = '  - '.$capability.': '.$reason;
            }
        }

        $lines[] = 'status: '.($report['ok'] ? 'ok' : 'refused');

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{modules: list<string>, refusals: list<string>, ok: bool}  $report
     */
    public function renderDomainOwnership(array $report): string
    {
        $lines = ['domain modules:'];
        $lines = [...$lines, ...$this->reportSection('', $report['modules'])];
        $lines[] = 'refusals:';
        $lines = [...$lines, ...$this->reportSection('', $report['refusals'])];
        $lines[] = 'status: '.($report['ok'] ? 'ok' : 'refused');

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $entries
     * @return list<string>
     */
    private function reportSection(string $heading, array $entries): array
    {
        $lines = $heading === '' ? [] : [$heading];

        if ($entries === []) {
            return [...$lines, self::EMPTY_REPORT_LINE];
        }

        return [...$lines, ...array_map(static fn (string $entry): string => '  - '.$entry, $entries)];
    }
}
