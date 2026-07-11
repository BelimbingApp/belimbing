<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:db:share:offer-revoke')]
class RevokeDataShareTransferOfferCommand extends Command
{
    protected $signature = 'blb:db:share:offer-revoke {offer : Public transfer offer ID}';

    protected $description = 'Revoke an available source-owned Data Share offer';

    public function handle(DataShareTransferOfferManager $offers): int
    {
        $offer = DataShareTransferOffer::query()->where('offer_id', (string) $this->argument('offer'))->firstOrFail();
        $offers->revoke($offer);
        $this->components->info('Transfer offer revoked.');

        return self::SUCCESS;
    }
}
