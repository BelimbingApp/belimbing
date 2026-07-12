<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:share:export')]
class ExportDataSharePackageCommand extends Command
{
    protected $signature = 'blb:db:share:export
                            {scope : Base-discovered module scope name}
                            {--table=* : Registered tables within the scope; defaults to the entire scope}
                            {--publish : Publish an immutable source offer in protected Outgoing storage}
                            {--preview-hash= : Exact preview hash required with --publish}
                            {--max-downloads=1 : Fetches allowed before the offer closes; 0 means unlimited}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Preview registered tables or publish a source-owned Data Share offer';

    public function handle(DataSharePackageExporter $exporter, DataShareTransferOfferManager $offers): int
    {
        try {
            $payload = $this->option('publish')
                ? $this->publishedPayload($offers)
                : $this->previewPayload($exporter);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($payload['mode'] === 'invalid') {
            return self::INVALID;
        }

        $this->writePayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function previewPayload(DataSharePackageExporter $exporter): array
    {
        $preview = $exporter->preview((string) $this->argument('scope'), $this->option('table'));

        return [
            ...$preview->report,
            'preview_sha256' => $preview->previewHash,
            'estimated_bytes' => $preview->estimatedBytes,
            'mode' => 'preview',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedPayload(DataShareTransferOfferManager $offers): array
    {
        $expected = trim((string) $this->option('preview-hash'));

        if ($expected === '') {
            $this->components->error('--preview-hash is required with --publish.');

            return ['mode' => 'invalid'];
        }

        $maximumOption = trim((string) $this->option('max-downloads'));

        if (preg_match('/^\d+$/', $maximumOption) !== 1
            || (int) $maximumOption > DataShareTransferOfferManager::MAX_DOWNLOADS) {
            $this->components->error('--max-downloads must be 0 (unlimited) or an integer from 1 to '.DataShareTransferOfferManager::MAX_DOWNLOADS.'.');

            return ['mode' => 'invalid'];
        }

        $maxDownloads = (int) $maximumOption;

        $offer = $offers->publish(
            (string) $this->argument('scope'),
            $this->option('table'),
            $expected,
            maxDownloads: $maxDownloads > 0 ? $maxDownloads : null,
        );

        return ['mode' => 'published', 'offer' => $offer->toArray()];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writePayload(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        $this->components->info($payload['mode'] === 'preview'
            ? 'Export preview only; no package was written.'
            : 'Transfer offer published. Its bundle can be copied again while the offer remains available.');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
