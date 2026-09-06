<?php

namespace App\Base\Livewire\Console\Commands;

use App\Base\Livewire\ActionInventory;
use App\Base\Livewire\ActionInventoryException;
use Illuminate\Console\Command;
use JsonException;

final class LivewireActionsCommand extends Command
{
    protected $signature = 'blb:livewire-actions
        {--domain= : Installed, enabled Domain directory name}
        {--json : Emit JSON instead of a table}
        {--explain : Name new unreferenced actions when the baseline check fails}
        {--check-baseline= : Fail when module-owned unreferenced count exceeds this JSON baseline}
        {--write-baseline= : Write the current module-owned unreferenced snapshot to this JSON path}';

    protected $description = 'List callable Livewire methods and lexical test references (not coverage)';

    public function handle(ActionInventory $inventory): int
    {
        $domain = $this->option('domain');
        $domain = is_string($domain) && $domain !== '' ? $domain : null;
        $checkBaseline = $this->option('check-baseline');
        $writeBaseline = $this->option('write-baseline');

        try {
            if (is_string($writeBaseline) && $writeBaseline !== '') {
                return $this->writeBaseline($inventory, $domain, $writeBaseline);
            }

            if (is_string($checkBaseline) && $checkBaseline !== '') {
                return $this->checkBaseline($inventory, $domain, $checkBaseline);
            }

            $rows = $inventory->scan($domain);
        } catch (ActionInventoryException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Component', 'Method', 'Origin', 'Test reference'], array_map(
                fn (array $row): array => [$row['component'], $row['method'],
                    $row['module_owned'] ? 'module' : 'shared', $row['referenced_in_tests'] ? 'yes' : 'no'],
                $rows,
            ));
            $this->comment('Test references are lexical matches, including strings/comments and unrelated components; they do not prove coverage.');
        }

        return self::SUCCESS;
    }

    private function writeBaseline(ActionInventory $inventory, ?string $domain, string $path): int
    {
        $snapshot = $inventory->baselineSnapshot($domain);
        $directory = dirname($path);
        if ($directory !== '.' && ! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            $this->error('Cannot create baseline directory: '.$directory);

            return self::FAILURE;
        }

        file_put_contents(
            $path,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
        $this->info(sprintf(
            'Wrote %s with module_owned_unreferenced=%d%s',
            $path,
            $snapshot['module_owned_unreferenced'],
            $domain !== null ? ' for '.$domain : '',
        ));

        return self::SUCCESS;
    }

    private function checkBaseline(ActionInventory $inventory, ?string $domain, string $path): int
    {
        if (! is_file($path)) {
            $this->error('Baseline file not found: '.$path);

            return self::FAILURE;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Baseline JSON is invalid: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $this->error('Baseline JSON must be an object.');

            return self::FAILURE;
        }

        /** @var array{domain?: string|null, module_owned_unreferenced?: mixed} $baseline */
        $baseline = $decoded;

        $result = $inventory->compareToBaseline($baseline, $domain);
        if ($result['ok']) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->error($result['message']);
        if ($this->option('explain')) {
            if (($result['new_actions'] ?? null) === null) {
                $this->comment('This baseline has no action list; only the count can be compared.');
            } else {
                foreach ($result['new_actions'] as $action) {
                    $this->line($action);
                }
            }
        }

        return self::FAILURE;
    }
}
