<?php

namespace App\Base\Database\Enums;

/**
 * Discriminator for the shared data operation ledger. Mirror is writer #1;
 * imports are writer #2. New writers add cases here.
 */
enum DataOperationType: string
{
    case MirrorPush = 'mirror_push';
    case MirrorForcePush = 'mirror_force_push';
    case MirrorPull = 'mirror_pull';
    case MirrorBaseline = 'mirror_baseline';
    case AxImport = 'ax_import';
    case InvestmentProcess = 'investment_process';

    public function isMirror(): bool
    {
        return match ($this) {
            self::MirrorPush, self::MirrorForcePush, self::MirrorPull, self::MirrorBaseline => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MirrorPush => 'Mirror push',
            self::MirrorForcePush => 'Mirror force push',
            self::MirrorPull => 'Mirror pull',
            self::MirrorBaseline => 'Baseline observation',
            self::AxImport => 'AX import',
            self::InvestmentProcess => 'Investment process',
        };
    }
}
