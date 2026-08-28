<?php

namespace App\Base\Schedule\DTO;

use App\Base\Schedule\Models\ScheduleRun;

/**
 * The outcome of ScheduleRunRecorder::reserveManualRun() — exactly one
 * concurrent reservation attempt for a key resolves `created`; every other
 * one resolves `refused`, decided inside the same locked transaction rather
 * than by a separate check the caller made beforehand (#407 review, luna/terra).
 */
final readonly class ScheduleRunReservation
{
    private function __construct(
        public bool $created,
        public ?ScheduleRun $run,
    ) {}

    public static function created(ScheduleRun $run): self
    {
        return new self(true, $run);
    }

    public static function refused(): self
    {
        return new self(false, null);
    }
}
