<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\Concerns\FormatsProcessResult;
use App\Base\AI\Tools\Concerns\HasToolMetadata;

abstract class AbstractHighImpactProcessTool extends AbstractTool
{
    use FormatsProcessResult;
    use HasToolMetadata;

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }
}
