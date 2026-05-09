<?php
namespace App\Base\AI\Console\Commands;

use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\AI\Services\ModelCatalogService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Sync the models.dev community catalog to local cache.
 */
#[AsCommand(name: 'blb:ai:catalog:sync')]
class AiCatalogSyncCommand extends Command
{
    protected $description = 'Sync the models.dev AI model catalog to local cache';

    protected $signature = 'blb:ai:catalog:sync
                            {--force : Force re-download (ignore ETag)}
                            {--stats : Show provider/model counts from cache without syncing}';

    public function handle(ModelCatalogService $catalog): int
    {
        if ($this->option('stats')) {
            return $this->showStats($catalog);
        }

        $this->components->info('Syncing models.dev catalog...');

        try {
            $result = $catalog->sync(force: (bool) $this->option('force'));
        } catch (ModelCatalogSyncException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->updated) {
            $this->components->info("Catalog updated: {$result->providerCount} providers, {$result->modelCount} models.");
        } else {
            $this->components->info("Catalog is up to date (ETag match): {$result->providerCount} providers, {$result->modelCount} models.");
        }

        return self::SUCCESS;
    }

    private function showStats(ModelCatalogService $catalog): int
    {
        $lastSynced = $catalog->lastSyncedAt();
        $isStale = $catalog->isStale();

        if ($lastSynced === null) {
            $this->components->warn('No catalog cached. Run blb:ai:catalog:sync to fetch.');

            return self::SUCCESS;
        }

        $providers = $catalog->getProviders();
        $totalModels = 0;

        foreach ($catalog->getCatalog() as $provider) {
            if (is_array($provider) && isset($provider['models']) && is_array($provider['models'])) {
                $totalModels += count($provider['models']);
            }
        }

        $this->components->twoColumnDetail('Last synced', $lastSynced->format('Y-m-d H:i:s T'));
        $this->components->twoColumnDetail('Stale', $isStale ? '<fg=yellow>Yes</>' : '<fg=green>No</>');
        $this->components->twoColumnDetail('Providers (catalog + overlay)', (string) count($providers));
        $this->components->twoColumnDetail('Models (catalog)', (string) $totalModels);

        return self::SUCCESS;
    }
}
