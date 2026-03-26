<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Services;

use App\Modules\Core\Quality\Contracts\NumberingService;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Models\Scar;

/**
 * Default sequential numbering for NCRs and SCARs.
 *
 * Generates numbers in the format PREFIX-000001. Licensees can
 * override this binding with their own NumberingService implementation.
 */
class DefaultNumberingService implements NumberingService
{
    /**
     * Generate the next NCR number.
     *
     * @param  string  $ncrKind  The NCR kind (internal, customer, etc.)
     */
    public function nextNcrNumber(string $ncrKind): string
    {
        $prefix = config('quality.numbering.ncr_prefix', 'NCR');
        $padLength = config('quality.numbering.pad_length', 6);

        $lastNumber = Ncr::query()
            ->where('ncr_no', 'like', $prefix.'-%')
            ->orderByDesc('id')
            ->value('ncr_no');

        $nextSequence = 1;

        if ($lastNumber !== null) {
            $parts = explode('-', $lastNumber);
            $nextSequence = ((int) end($parts)) + 1;
        }

        return $prefix.'-'.str_pad((string) $nextSequence, $padLength, '0', STR_PAD_LEFT);
    }

    /**
     * Generate the next SCAR number.
     */
    public function nextScarNumber(): string
    {
        $prefix = config('quality.numbering.scar_prefix', 'SCAR');
        $padLength = config('quality.numbering.pad_length', 6);

        $lastNumber = Scar::query()
            ->where('scar_no', 'like', $prefix.'-%')
            ->orderByDesc('id')
            ->value('scar_no');

        $nextSequence = 1;

        if ($lastNumber !== null) {
            $parts = explode('-', $lastNumber);
            $nextSequence = ((int) end($parts)) + 1;
        }

        return $prefix.'-'.str_pad((string) $nextSequence, $padLength, '0', STR_PAD_LEFT);
    }
}
