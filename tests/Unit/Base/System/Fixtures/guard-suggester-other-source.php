<?php

namespace App\Base\System\Fixtures;

final class SuggestedOtherGuards
{
    public function status(bool $allowed): ?string
    {
        abort_unless($allowed, 404);

        if (! $allowed) {
            return null;
        }

        return 'ready';
    }
}
