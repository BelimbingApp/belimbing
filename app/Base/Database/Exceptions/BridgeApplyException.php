<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;

class BridgeApplyException extends RuntimeException
{
    public static function confirmationRequired(): self
    {
        return new self(__('Data Bridge apply requires explicit confirmation.'));
    }

    public static function hashMismatch(): self
    {
        return new self(__('The reviewed Data Bridge package or plan hash does not match.'));
    }

    public static function planNotReady(string $status): self
    {
        return new self(__('Data Bridge plan is not ready to apply (status: :status).', ['status' => $status]));
    }

    public static function replay(string $packageId): self
    {
        return new self(__('Data Bridge package :package has already been applied.', ['package' => $packageId]));
    }

    public static function stalePlan(): self
    {
        return new self(__('Destination data changed after preview. Build and review a new Data Bridge plan.'));
    }

    public static function locked(): self
    {
        return new self(__('Another Data Bridge apply is already running.'));
    }

    public static function backupRequired(string $reason): self
    {
        return new self(__('Production Data Bridge apply requires a fresh verified backup: :reason', ['reason' => $reason]));
    }
}
