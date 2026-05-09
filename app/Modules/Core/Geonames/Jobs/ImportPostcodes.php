<?php
namespace App\Modules\Core\Geonames\Jobs;

use App\Modules\Core\Geonames\Database\Seeders\PostcodeSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class ImportPostcodes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Queue dedicated to postcode imports so worker --once can target it.
     */
    public const QUEUE = 'geonames-postcodes';

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     *
     * @param  array<int, string>  $countryCodes  ISO country codes to import
     */
    public function __construct(
        public array $countryCodes,
    ) {
        $this->countryCodes = array_values(array_unique(array_map('strtoupper', $countryCodes)));
        sort($this->countryCodes);
        $this->onQueue(self::QUEUE);
    }

    /**
     * Human-readable name shown in payloads/logs.
     */
    public function displayName(): string
    {
        return 'ImportPostcodes['.implode(',', $this->countryCodes).']';
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        app(PostcodeSeeder::class)->run($this->countryCodes);
    }

    /**
     * Run the queue worker once so a dispatched job is processed.
     *
     * Call after dispatch() when no long-running worker is available.
     */
    public static function runWorkerOnce(): void
    {
        Artisan::call('queue:work', [
            '--once' => true,
            '--queue' => self::QUEUE,
        ]);
    }
}
