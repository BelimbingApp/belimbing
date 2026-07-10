<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\Services\Bridge\BridgePackageExporter;
use App\Base\Database\Services\Bridge\BridgePackageSender;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:bridge:export')]
class ExportBridgePackageCommand extends Command
{
    protected $signature = 'blb:db:bridge:export
                            {scope : Base-discovered module scope name}
                            {--table=* : Registered tables within the scope; defaults to the entire scope}
                            {--receive-grant-file= : File containing the target-issued copy-once receive bundle}
                            {--receive-endpoint= : Exact advertised LAN or Cloudflare receive endpoint; defaults to the first}
                            {--commit : Write the reviewed package to protected Outgoing storage}
                            {--send : Stream the committed package to its one-time receive endpoint}
                            {--preview-hash= : Exact preview hash required with --commit}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Preview or export registered tables through Data Bridge';

    public function handle(BridgePackageExporter $exporter, BridgePackageSender $sender): int
    {
        $grantPath = trim((string) $this->option('receive-grant-file'));

        if ($grantPath === '' || ! is_file($grantPath)) {
            $this->components->error('--receive-grant-file must name a readable copy-once receive bundle file.');

            return self::INVALID;
        }

        if ($this->option('send') && ! $this->option('commit')) {
            $this->components->error('--send requires --commit and the exact reviewed --preview-hash.');

            return self::INVALID;
        }

        try {
            $grant = BridgeReceiveGrantBundle::fromJson((string) file_get_contents($grantPath));
            $endpoint = trim((string) $this->option('receive-endpoint'));

            if ($endpoint !== '') {
                $grant = $grant->usingEndpoint($endpoint);
            }

            if (! $this->option('commit')) {
                $preview = $exporter->preview((string) $this->argument('scope'), $this->option('table'), $grant);
                $payload = [
                    ...$preview->report,
                    'preview_sha256' => $preview->previewHash,
                    'estimated_bytes' => $preview->estimatedBytes,
                    'mode' => 'preview',
                ];
            } else {
                $expected = trim((string) $this->option('preview-hash'));

                if ($expected === '') {
                    $this->components->error('--preview-hash is required with --commit.');

                    return self::INVALID;
                }

                $result = $exporter->export((string) $this->argument('scope'), $this->option('table'), $grant, $expected);
                $payload = [
                    'mode' => $this->option('send') ? 'sent' : 'exported',
                    'package_id' => $result->packageId,
                    'path' => $result->path,
                    'sha256' => $result->sha256,
                    'bytes' => $result->bytes,
                    'manifest' => $result->manifest,
                ];

                if ($this->option('send')) {
                    $payload['receipt'] = $sender->send($result, $grant);
                }
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($payload);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }

        $this->components->info(match ($payload['mode']) {
            'preview' => 'Export preview only; no package was written.',
            'sent' => 'Package exported, streamed, and admitted to target Incoming.',
            default => 'Package exported.',
        });

        foreach (['preview_sha256', 'package_id', 'path', 'sha256', 'estimated_bytes', 'bytes'] as $key) {
            if (array_key_exists($key, $payload)) {
                $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($key)), (string) $payload[$key]);
            }
        }
    }
}
