<?php
namespace App\Base\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;

abstract class AbstractReadOnlyMemoryTool extends AbstractTool
{
    use ProvidesToolMetadata;

    public function category(): ToolCategory
    {
        return ToolCategory::MEMORY;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }
}
