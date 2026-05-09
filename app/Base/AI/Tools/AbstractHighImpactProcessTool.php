<?php
namespace App\Base\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\Concerns\FormatsProcessResult;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;

abstract class AbstractHighImpactProcessTool extends AbstractTool
{
    use FormatsProcessResult;
    use ProvidesToolMetadata;

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }
}
