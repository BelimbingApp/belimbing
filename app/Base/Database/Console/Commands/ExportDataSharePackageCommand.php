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
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Preview registered tables or publish a source-owned Data Share offer';

    public function handle(DataSharePackageExporter $exporter, DataShareTransferOfferManager $offers): int
    {
        try {
            if (! $this->option('publish')) {
                $preview = $exporter->preview((string) $this->argument('scope'), $this->option('table'));
                $payload = [
                    ...$preview->report,
                    'preview_sha256' => $preview->previewHash,
                    'estimated_bytes' => $preview->estimatedBytes,
                    'mode' => 'preview',
                ];
            } else {
                $expected = trim((string) $this->option('preview-hash'));

                if ($expected === '') {
                    $this->components->error('--preview-hash is required with --publish.');

                    return self::INVALID;
                }

                $offer = $offers->publish(
                    (string) $this->argument('scope'),
                    $this->option('table'),
                    $expected,
                );
                $payload = ['mode' => 'published', 'offer' => $offer->toArray()];
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->info($payload['mode'] === 'preview'
            ? 'Export preview only; no package was written.'
            : 'Transfer offer published. Copy the offer JSON now; the plaintext secret is not stored.');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
