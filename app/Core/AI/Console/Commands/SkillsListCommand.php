<?php

namespace App\Core\AI\Console\Commands;

use App\Core\AI\DTO\Orchestration\SkillPackManifest;
use App\Core\AI\Services\Orchestration\FilesystemSkillPackLoader;
use App\Core\AI\Services\Orchestration\SkillPackVerifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * List the filesystem skill packs a composed tree exposes.
 *
 * The loader is forgiving by design — a `SKILL.md` with no frontmatter still
 * produces a `Ready` manifest with a slug-derived name — and it drops a
 * duplicate id without a word. Both are invisible from the manifest alone, so
 * this command carries a `Checks` column naming what a pack failed to declare
 * and a `Shadowed` section naming which root won each duplicate id.
 */
#[AsCommand(name: 'blb:ai:skills:list')]
class SkillsListCommand extends Command
{
    protected $description = 'List filesystem skill packs, what they declare, and which duplicates are shadowed';

    protected $signature = 'blb:ai:skills:list {--json : Emit the same rows as JSON}';

    public function handle(FilesystemSkillPackLoader $loader, SkillPackVerifier $verifier): int
    {
        $manifests = $loader->load();
        $shadowed = $loader->shadowed();

        usort($manifests, static fn (SkillPackManifest $a, SkillPackManifest $b): int => strcmp($a->id, $b->id));

        $rows = array_map(
            fn (SkillPackManifest $manifest): array => [
                'id' => $manifest->id,
                'owner' => $manifest->owner ?? '',
                'name' => $manifest->name,
                'status' => $manifest->status->value,
                'checks' => $this->checksColumn($verifier->failedKeys($manifest)),
                'path' => $manifest->primaryReferencePath(),
            ],
            $manifests,
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['packs' => $rows, 'shadowed' => $shadowed],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->components->info(count($rows).' skill pack(s) listed.');

        if ($rows !== []) {
            $this->table(
                ['ID', 'Owner', 'Name', 'Status', 'Checks', 'Path'],
                array_map(array_values(...), $rows),
            );
        }

        if ($shadowed !== []) {
            // Plain lines, not $this->components: the console components wrap
            // at the terminal width, and a path split across two lines is
            // unusable as the thing an operator has to go and delete.
            $this->newLine();
            $this->line(count($shadowed).' shadowed skill pack id(s):');

            foreach ($shadowed as $entry) {
                $this->line('  '.$entry['id'].': kept '.$entry['kept'].', dropped '.$entry['dropped']);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $failedKeys
     */
    private function checksColumn(array $failedKeys): string
    {
        return $failedKeys === [] ? 'ok' : implode(',', $failedKeys);
    }
}
