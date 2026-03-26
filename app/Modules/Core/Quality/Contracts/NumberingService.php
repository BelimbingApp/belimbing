<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Contracts;

interface NumberingService
{
    /**
     * Generate the next NCR number.
     *
     * @param  string  $ncrKind  The NCR kind (internal, customer, etc.)
     */
    public function nextNcrNumber(string $ncrKind): string;

    /**
     * Generate the next SCAR number.
     */
    public function nextScarNumber(): string;
}
