<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Exceptions;

/**
 * Thrown when no enabled channel account exists for a company/channel pair.
 */
final class NoChannelAccountException extends \RuntimeException
{
    public function __construct(string $channel, int $companyId)
    {
        parent::__construct(
            'No enabled "'.$channel.'" account found for company '.$companyId.'. '
            .'Configure a channel account before sending messages.',
        );
    }
}
