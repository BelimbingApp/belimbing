<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\ProvidesToolMetadata;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\Services\Orchestration\SkillSelectionService;
use App\Modules\Core\AI\Services\Runtime\RuntimeSessionContext;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Loads a discovered skill pack's procedure into the current turn.
 *
 * Progressive disclosure: the runtime context lists skill ids; this tool
 * pulls the full SKILL.md body when Lara needs the procedure.
 */
class LoadSkillTool extends AbstractTool
{
    use ProvidesToolMetadata;

    public function __construct(
        private readonly SkillSelectionService $selection,
        private readonly RuntimeSessionContext $sessionContext,
    ) {}

    public function category(): ToolCategory
    {
        return ToolCategory::CONTEXT;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function name(): string
    {
        return 'load_skill';
    }

    public function description(): string
    {
        return 'Load a discovered skill pack by id (from runtime context skills.catalog) and return its full procedure. '
            .'Use when the user asks to follow a skill, or when a catalog entry matches the task. '
            .'Prefer page-suggested skills when present.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string('skill_id', 'Skill pack id from skills.catalog (e.g. extension.kiat.verify-annual-report), or a trailing slug.')->required();
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.load-skill.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Load Skill',
            'summary' => 'Load an ownership-scoped skill procedure into the turn.',
            'explanation' => 'Returns the full SKILL.md guidance for a registered skill pack. '
                .'Does not execute the skill — Lara follows the procedure with other tools.',
            'setup_requirements' => [
                'Skill packs discovered under .agents/skills roots',
            ],
            'test_examples' => [
                [
                    'label' => 'Load by id',
                    'input' => ['skill_id' => 'extension.kiat.verify-annual-report'],
                ],
            ],
            'health_checks' => [
                'SkillPackRegistry has packs',
            ],
            'limits' => [
                'One skill body per call',
                'Unknown or disabled packs are refused',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        $skillId = $this->requireString($arguments, 'skill_id');
        $employeeId = $this->sessionContext->recall('employee_id');
        $employeeId = is_int($employeeId) ? $employeeId : Employee::LARA_ID;

        $manifest = $this->selection->resolvePack($skillId, $employeeId);

        if ($manifest === null) {
            return ToolResult::error(
                'Unknown or unavailable skill pack "'.$skillId.'". Check skills.catalog in runtime context for valid ids.',
            );
        }

        $body = [];

        foreach ($manifest->promptResources as $resource) {
            $body[] = $resource->content;
        }

        if ($body === []) {
            return ToolResult::error('Skill pack "'.$manifest->id.'" has no prompt resources.');
        }

        $resolved = $this->sessionContext->recall('resolved_skill_pack_ids');
        $resolved = is_array($resolved) ? $resolved : [];

        if (! in_array($manifest->id, $resolved, true)) {
            $resolved[] = $manifest->id;
            $this->sessionContext->remember('resolved_skill_pack_ids', $resolved);
        }

        $path = $manifest->references[0]->path ?? null;
        $header = 'Loaded skill `'.$manifest->id.'`';
        if (is_string($path) && $path !== '') {
            $header .= ' ('.$path.')';
        }
        $header .= ".\nFollow this procedure with available tools. Target the owning repository surface when editing.\n\n";

        return ToolResult::success($header.implode("\n\n", $body));
    }
}
