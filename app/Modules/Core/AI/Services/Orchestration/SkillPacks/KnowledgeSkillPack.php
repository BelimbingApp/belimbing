<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Orchestration\SkillPacks;

use App\Base\AI\Services\KnowledgeNavigator;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackPromptResource;
use App\Modules\Core\AI\DTO\Orchestration\SkillPackReference;
use App\Modules\Core\AI\Enums\SkillPackStatus;

/**
 * Framework knowledge skill pack.
 *
 * Packages the KnowledgeNavigator's curated Belimbing documentation catalog
 * as a skill pack manifest. This is the first real skill pack in Belimbing,
 * proving the abstraction works with actual framework content.
 *
 * The pack provides:
 * - prompt resources with framework reference grounding
 * - reference documents from the curated documentation catalog
 * - tool binding to the guide_search tool
 *
 * This pack applies to all agents (empty applicableAgentIds) since
 * any agent may benefit from framework knowledge.
 */
class KnowledgeSkillPack
{
    public const PACK_ID = 'blb.framework-knowledge';

    private const PACK_VERSION = '1.0.0';

    private const PACK_NAME = 'Belimbing Framework Knowledge';

    private const PACK_DESCRIPTION = 'Curated Belimbing framework documentation references and search capability';

    private const PACK_OWNER = 'Core AI';

    public function __construct(
        private readonly KnowledgeNavigator $navigator,
    ) {}

    /**
     * Build the skill pack manifest from live KnowledgeNavigator data.
     */
    public function manifest(): SkillPackManifest
    {
        return new SkillPackManifest(
            id: self::PACK_ID,
            version: self::PACK_VERSION,
            name: self::PACK_NAME,
            description: self::PACK_DESCRIPTION,
            owner: self::PACK_OWNER,
            applicableAgentIds: [],
            applicableRoles: [],
            promptResources: $this->buildPromptResources(),
            toolBindings: ['guide_search'],
            references: $this->buildReferences(),
            readinessChecks: ['KnowledgeNavigator catalog is non-empty'],
            hookBindings: [],
            status: SkillPackStatus::Ready,
        );
    }

    /**
     * Build prompt resources from the navigator's default references.
     *
     * @return list<SkillPackPromptResource>
     */
    private function buildPromptResources(): array
    {
        $references = $this->navigator->defaultReferences(6);

        if ($references === []) {
            return [];
        }

        $referenceList = '';

        foreach ($references as $index => $ref) {
            $num = $index + 1;
            $referenceList .= "{$num}. **{$ref['title']}** — {$ref['summary']} (`{$ref['path']}`)\n";
        }

        return [
            new SkillPackPromptResource(
                label: 'framework-knowledge-grounding',
                content: "## Belimbing Framework References\n\n"
                    ."The following curated references are available for framework knowledge:\n\n"
                    .$referenceList."\n"
                    .'Use the `guide_search` tool to search for specific topics across these references.',
                order: 200,
            ),
        ];
    }

    /**
     * Build reference documents from the full navigator catalog.
     *
     * @return list<SkillPackReference>
     */
    private function buildReferences(): array
    {
        return array_map(
            fn (array $entry): SkillPackReference => new SkillPackReference(
                title: $entry['title'],
                path: $entry['path'],
                summary: $entry['summary'],
            ),
            $this->navigator->catalog(),
        );
    }
}
