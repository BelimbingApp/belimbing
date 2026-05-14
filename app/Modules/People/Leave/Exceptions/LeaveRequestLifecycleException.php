<?php

namespace App\Modules\People\Leave\Exceptions;

use RuntimeException;

final class LeaveRequestLifecycleException extends RuntimeException
{
    public static function invalidStatus(int $requestId, string $status, string $action): self
    {
        return new self(sprintf(
            'Leave request %d in status [%s] cannot be %s.',
            $requestId,
            $status,
            $action,
        ));
    }
}
