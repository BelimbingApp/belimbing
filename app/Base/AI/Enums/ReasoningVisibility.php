<?php
namespace App\Base\AI\Enums;

enum ReasoningVisibility: string
{
    case None = 'none';
    case Summary = 'summary';
    case Full = 'full';
}
