<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Services\DataShare\DataShareOfferFetcher;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'blb:db:share:fetch')]
class FetchDataShareTransferOfferCommand extends Command
{
    protected $signature = 'blb:db:share:fetch
                            {--offer-file= : Protected file containing a source-published transfer-offer bundle}
                            {--offer-endpoint= : Exact advertised LAN or Cloudflare endpoint; defaults to the first}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'Fetch and verify a source-published Data Share offer into Incoming';

    public function handle(DataShareOfferFetcher $fetcher): int
    {
        $path = trim((string) $this->option('offer-file'));

        if ($path === '' || ! is_file($path)) {
            $this->components->error('--offer-file must name a readable transfer-offer bundle file.');

            return self::INVALID;
        }

        try {
            $offer = DataShareTransferOfferBundle::fromJson((string) file_get_contents($path));
            $endpoint = trim((string) $this->option('offer-endpoint'));
            $receipt = $fetcher->fetch($endpoint === '' ? $offer : $offer->usingEndpoint($endpoint));
            $payload = [
                'status' => $receipt->status,
                'offer_id' => $receipt->offer_id,
                'package_id' => $receipt->package_id,
                'sha256' => $receipt->package_sha256,
            ];
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($this->option('json')
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : 'Offer fetched and admitted to Incoming: '.$receipt->package_id);

        return self::SUCCESS;
    }
}
