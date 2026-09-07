<?php

namespace App\Core\AI\Console\Commands;

use App\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Core\AI\Services\Orchestration\FilesystemSkillPackLoader;
use App\Core\AI\Services\Orchestration\SkillPackVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Verify one filesystem skill pack declares what its manifest claims.
 *
 * Exits non-zero when the pack id is unknown or when any check fails, so a
 * malformed `SKILL.md` — which the loader would otherwise surface as `Ready`
 * with a slug-derived name — fails a pipeline instead of passing it.
 */
#[AsCommand(name: 'blb:ai:skills:verify')]
class SkillsVerifyCommand extends Command
{
    protected $description = 'Verify a filesystem skill pack declares a name, a description, and a usable body';

    protected $signature = 'blb:ai:skills:verify {pack : Skill pack id, e.g. core.impeccable}';

    public function handle(FilesystemSkillPackLoader $loader, SkillPackVerifier $verifier): int
    {
        $packId = (string) $this->argument('pack');
        $manifest = $this->find($loader, $packId);

        // Plain lines throughout, not $this->components: the console
        // components wrap at the terminal width, which would split a pack id
        // or a check label across lines in exactly the CI logs that need to
        // be greppable.
        if ($manifest === null) {
            $this->line('unknown skill pack '.$packId);
            $this->line('Run blb:ai:skills:list to see what this tree exposes.');

            return self::FAILURE;
        }

        $checks = $verifier->checks($manifest);
        $failed = 0;

        foreach ($checks as $check) {
            $this->line(($check['passed'] ? 'PASS  ' : 'FAIL  ').$check['label']);

            if (! $check['passed']) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->line($failed.' of '.count($checks).' checks failed for '.$packId);

            return self::FAILURE;
        }

        $this->line('all '.count($checks).' checks passed for '.$packId);

        return self::SUCCESS;
    }

    private function find(FilesystemSkillPackLoader $loader, string $packId): ?SkillPackManifest
    {
        foreach ($loader->load() as $manifest) {
            if ($manifest->id === $packId) {
                return $manifest;
            }
        }

        return null;
    }
}
