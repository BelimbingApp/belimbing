<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;

/**
 * Rich UI metadata for a Digital Worker tool.
 *
 * Separate from the runtime Tool contract — this holds
 * display-oriented information for the Tool Workspace UI.
 */
final readonly class ToolMetadata
{
    /**
     * @param  string  $name  Machine name matching Tool::name()
     * @param  string  $displayName  Human-friendly name for UI display
     * @param  string  $summary  One-sentence plain-language description
     * @param  string  $explanation  Longer description: what it does and does not do
     * @param  ToolCategory  $category  Grouping category for catalog filtering
     * @param  ToolRiskClass  $riskClass  Risk classification badge
     * @param  ?string  $capability  Required authz capability key
     * @param  list<string>  $setupRequirements  Human-readable setup checklist items
     * @param  list<array{label: string, input: array<string, mixed>}>  $testExamples  Sample inputs for the Try-It console
     * @param  list<string>  $healthChecks  Descriptions of health probes this tool supports
     * @param  list<string>  $limits  Known safety limits users should understand
     * @param  list<ToolConfigField>  $configFields  Configurable settings for this tool
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public string $summary,
        public string $explanation,
        public ToolCategory $category,
        public ToolRiskClass $riskClass,
        public ?string $capability,
        public array $setupRequirements = [],
        public array $testExamples = [],
        public array $healthChecks = [],
        public array $limits = [],
        public array $configFields = [],
    ) {}
}
