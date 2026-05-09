<?php
namespace App\Base\AI\Enums;

enum ReasoningMode: string
{
    case Auto = 'auto';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
