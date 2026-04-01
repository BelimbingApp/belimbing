<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Types of durable browser artifacts.
 */
enum BrowserArtifactType: string
{
    case Snapshot = 'snapshot';
    case Screenshot = 'screenshot';
    case Pdf = 'pdf';
    case EvaluateResult = 'evaluate_result';

    public function label(): string
    {
        return match ($this) {
            self::Snapshot => __('Page Snapshot'),
            self::Screenshot => __('Screenshot'),
            self::Pdf => __('PDF Export'),
            self::EvaluateResult => __('JS Evaluation Result'),
        };
    }

    public function mimeType(): string
    {
        return match ($this) {
            self::Snapshot => 'text/plain',
            self::Screenshot => 'image/png',
            self::Pdf => 'application/pdf',
            self::EvaluateResult => 'application/json',
        };
    }
}
