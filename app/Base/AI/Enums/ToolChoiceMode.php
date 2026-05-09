<?php
namespace App\Base\AI\Enums;

enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Required = 'required';
}
