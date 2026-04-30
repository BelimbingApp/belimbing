<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane\WireLog;

/**
 * @internal
 *
 * Collects operator-visible anomaly signals from the currently loaded wire-log window.
 */
final class AnomalyCollector
{
    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<array<string, mixed>>  $attempts
     * @return list<array<string, mixed>>
     */
    public function collectAnomalies(array $entries, array $attempts): array
    {
        $anomalies = [];

        $previewSignals = $this->collectPreviewSignals($entries);
        $decodeErrors = $previewSignals['decode_errors'];
        $omittedEntries = $previewSignals['omitted_entries'];

        $unknownSignals = $this->collectUnknownKeySignals($attempts);
        $unknownKeys = $unknownSignals['unknown_keys'];
        $unknownKeyEntries = $unknownSignals['unknown_key_entries'];

        foreach ($attempts as $attempt) {
            $this->appendAttemptAnomalies($anomalies, $attempt);
        }

        $this->appendDecodeErrorAnomaly($anomalies, $decodeErrors);
        $this->appendOversizedAnomaly($anomalies, $omittedEntries);
        $this->appendUnknownKeysAnomaly($anomalies, $unknownKeys, $unknownKeyEntries);

        return $anomalies;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{decode_errors: list<int>, omitted_entries: list<int>}
     */
    private function collectPreviewSignals(array $entries): array
    {
        $decodeErrors = [];
        $omittedEntries = [];

        foreach ($entries as $entry) {
            $previewStatus = is_string($entry['preview_status'] ?? null) ? $entry['preview_status'] : 'full';
            $entryNumber = (int) ($entry['entry_number'] ?? 0);

            if ($previewStatus === 'decode_error' || $previewStatus === 'encode_error') {
                $decodeErrors[] = $entryNumber;
            }

            if ($previewStatus === 'line_omitted' || $previewStatus === 'payload_truncated') {
                $omittedEntries[] = $entryNumber;
            }
        }

        return [
            'decode_errors' => $decodeErrors,
            'omitted_entries' => $omittedEntries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attempts
     * @return array{unknown_keys: array<string, true>, unknown_key_entries: list<int>}
     */
    private function collectUnknownKeySignals(array $attempts): array
    {
        $unknownKeys = [];
        $unknownKeyEntries = [];

        foreach ($attempts as $attempt) {
            foreach ($attempt['sections'] as $section) {
                if (($section['kind'] ?? null) !== 'stream_block') {
                    continue;
                }

                foreach ($section['unknown_keys'] as $key) {
                    $unknownKeys[$key] = true;
                }

                foreach ($section['unknown_key_entries'] ?? [] as $entryNumber) {
                    $unknownKeyEntries[] = (int) $entryNumber;
                }
            }
        }

        return [
            'unknown_keys' => $unknownKeys,
            'unknown_key_entries' => $unknownKeyEntries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $anomalies
     * @param  array<string, mixed>  $attempt
     */
    private function appendAttemptAnomalies(array &$anomalies, array $attempt): void
    {
        $statusCode = $attempt['status_code'] ?? null;

        if (is_int($statusCode) && ($statusCode < 200 || $statusCode >= 300)) {
            $anomalies[] = [
                'type' => 'http_error',
                'severity' => 'danger',
                'label' => __('HTTP :status', ['status' => $statusCode]),
                'detail' => __('Attempt :index returned a non-2xx status.', ['index' => $attempt['index']]),
                'entry_numbers' => [],
            ];
        }

        $finishReason = $attempt['finish_reason'] ?? null;

        if (is_string($finishReason) && ! in_array($finishReason, ['', 'stop'], true)) {
            $anomalies[] = [
                'type' => 'finish_reason',
                'severity' => StreamAssembler::finishReasonSeverity($finishReason),
                'label' => __('Finish reason: :reason', ['reason' => $finishReason]),
                'detail' => __('Attempt :index ended with :reason rather than stop.', [
                    'index' => $attempt['index'],
                    'reason' => $finishReason,
                ]),
                'entry_numbers' => [],
            ];
        }

        if (is_string($attempt['error_message'] ?? null) && $attempt['error_message'] !== '') {
            $anomalies[] = [
                'type' => 'transport_error',
                'severity' => 'danger',
                'label' => __('Transport error'),
                'detail' => $attempt['error_message'],
                'entry_numbers' => [],
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $anomalies
     * @param  list<int>  $decodeErrors
     */
    private function appendDecodeErrorAnomaly(array &$anomalies, array $decodeErrors): void
    {
        if ($decodeErrors === []) {
            return;
        }

        $anomalies[] = [
            'type' => 'decode_error',
            'severity' => 'warning',
            'label' => __(':count decode errors', ['count' => count($decodeErrors)]),
            'detail' => __('Some entries could not be decoded as JSON.'),
            'entry_numbers' => $decodeErrors,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $anomalies
     * @param  list<int>  $omittedEntries
     */
    private function appendOversizedAnomaly(array &$anomalies, array $omittedEntries): void
    {
        if ($omittedEntries === []) {
            return;
        }

        $anomalies[] = [
            'type' => 'oversized',
            'severity' => 'warning',
            'label' => __(':count oversized entries', ['count' => count($omittedEntries)]),
            'detail' => __('Some entries exceeded preview limits and must be opened raw.'),
            'entry_numbers' => $omittedEntries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $anomalies
     * @param  array<string, true>  $unknownKeys
     * @param  list<int>  $unknownKeyEntries
     */
    private function appendUnknownKeysAnomaly(array &$anomalies, array $unknownKeys, array $unknownKeyEntries): void
    {
        if ($unknownKeys === []) {
            return;
        }

        $anomalies[] = [
            'type' => 'unknown_keys',
            'severity' => 'warning',
            'label' => __('Unknown delta keys'),
            'detail' => __('Provider delta contained keys the formatter does not recognize: :keys', [
                'keys' => implode(', ', array_keys($unknownKeys)),
            ]),
            'entry_numbers' => array_values(array_unique($unknownKeyEntries)),
        ];
    }
}
