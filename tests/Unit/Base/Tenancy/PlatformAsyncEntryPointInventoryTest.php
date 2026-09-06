<?php

use App\Base\Tenancy\Exceptions\PlatformAsyncEntryPointInventoryException;
use App\Base\Tenancy\Support\PlatformAsyncEntryPointInventory;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('renders markdown that lists every declared job and scheduled command', function (): void {
    $markdown = PlatformAsyncEntryPointInventory::markdown();

    foreach (PlatformAsyncEntryPointInventory::queuedJobClasses() as $job) {
        expect($markdown)->toContain('`'.$job.'`');
    }

    foreach (PlatformAsyncEntryPointInventory::scheduledCommandSignatures() as $signature) {
        expect($markdown)->toContain('`'.$signature.'`');
    }
});

it('maps app-relative paths to App classes and refuses paths outside app/', function (): void {
    expect(PlatformAsyncEntryPointInventory::classFromPath('/tmp/repo/app/Base/Pdf/Jobs/RenderPdfJob.php'))
        ->toBe('App\\Base\\Pdf\\Jobs\\RenderPdfJob')
        ->and(PlatformAsyncEntryPointInventory::classFromPath('/tmp/outside/RenderPdfJob.php'))
        ->toBeNull();
});

it('discovers only concrete ShouldQueue jobs under supplied roots', function (): void {
    $root = storage_path('framework/testing/async-inventory-'.bin2hex(random_bytes(4)));
    $jobs = $root.'/app/Base/Tenancy/Jobs';
    File::ensureDirectoryExists($jobs);

    try {
        file_put_contents($jobs.'/ConcreteProbeJob.php', <<<'PHP'
<?php
namespace App\Base\Tenancy\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
final class ConcreteProbeJob implements ShouldQueue {}
PHP);
        file_put_contents($jobs.'/AbstractProbeJob.php', <<<'PHP'
<?php
namespace App\Base\Tenancy\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
abstract class AbstractProbeJob implements ShouldQueue {}
PHP);
        file_put_contents($jobs.'/NotQueuedProbe.php', <<<'PHP'
<?php
namespace App\Base\Tenancy\Jobs;
final class NotQueuedProbe {}
PHP);
        file_put_contents($jobs.'/notes.txt', 'ignore');

        $support = $root.'/app/Base/Tenancy/Support';
        File::ensureDirectoryExists($support);
        file_put_contents($support.'/NotAJobProbe.php', <<<'PHP'
<?php
namespace App\Base\Tenancy\Support;
final class NotAJobProbe {}
PHP);

        // Force classmap/autoload for the temporary namespace via require.
        require $jobs.'/ConcreteProbeJob.php';
        require $jobs.'/AbstractProbeJob.php';
        require $jobs.'/NotQueuedProbe.php';
        require $support.'/NotAJobProbe.php';

        $discovered = PlatformAsyncEntryPointInventory::discoverQueuedJobClasses([$root.'/app']);

        expect($discovered)->toBe(['App\\Base\\Tenancy\\Jobs\\ConcreteProbeJob'])
            ->and(PlatformAsyncEntryPointInventory::discoverQueuedJobClasses(['/tmp/does-not-exist-'.uniqid()]))
            ->toBe([]);
    } finally {
        File::deleteDirectory($root);
    }
});

it('builds a dispatchable instance for every declared queued job class', function (): void {
    $fixtures = PlatformAsyncEntryPointInventory::dispatchableQueuedJobs();
    $fixtureClasses = array_map(fn (array $pair): string => $pair[0], $fixtures);

    expect($fixtureClasses)->toBe(PlatformAsyncEntryPointInventory::queuedJobClasses());

    foreach ($fixtures as [$class, $job]) {
        expect($job)->toBeInstanceOf($class);
    }
});

it('throws a tenancy inventory exception when discovery drifts from the declaration', function (): void {
    $root = storage_path('framework/testing/async-inventory-drift-'.bin2hex(random_bytes(4)));
    // Empty root discovers nothing while declaration is non-empty.
    File::ensureDirectoryExists($root);

    try {
        expect(fn () => PlatformAsyncEntryPointInventory::assertJobsMatchDiscovery([$root]))
            ->toThrow(PlatformAsyncEntryPointInventoryException::class);
    } finally {
        File::deleteDirectory($root);
    }
});
