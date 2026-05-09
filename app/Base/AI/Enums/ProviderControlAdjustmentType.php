<?php
namespace App\Base\AI\Enums;

enum ProviderControlAdjustmentType: string
{
    case Forced = 'forced';
    case Unsupported = 'unsupported';
}
