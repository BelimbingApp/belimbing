<?php

use App\Base\Tenancy\Exceptions\PlatformAsyncEntryPointInventoryException;
use App\Base\Tenancy\Support\PlatformAsyncEntryPointInventory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
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

it('builds a dispatchable instance for every declared queued job', function (): void {
    $pairs = PlatformAsyncEntryPointInventory::dispatchableQueuedJobs();
    $declared = PlatformAsyncEntryPointInventory::queuedJobClasses();

    expect($pairs)->toHaveCount(count($declared));

    foreach ($pairs as [$class, $job]) {
        expect($declared)->toContain($class)
            ->and($job)->toBeInstanceOf($class);
    }
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
        // Filename maps to a class that was never loaded — class_exists fails closed.
        file_put_contents($jobs.'/MissingAutoloadJob.php', <<<'PHP'
<?php
namespace App\Base\Tenancy\Jobs;
// Intentionally empty: path implies MissingAutoloadJob but the class is absent.
PHP);
        file_put_contents($jobs.'/notes.txt', 'ignore');

        // Force classmap/autoload for the temporary namespace via require.
        require $jobs.'/ConcreteProbeJob.php';
        require $jobs.'/AbstractProbeJob.php';
        require $jobs.'/NotQueuedProbe.php';

        $discovered = PlatformAsyncEntryPointInventory::discoverQueuedJobClasses([$root.'/app']);

        expect($discovered)->toBe(['App\\Base\\Tenancy\\Jobs\\ConcreteProbeJob'])
            ->and(PlatformAsyncEntryPointInventory::discoverQueuedJobClasses(['/tmp/does-not-exist-'.uniqid()]))
            ->toBe([]);
    } finally {
        File::deleteDirectory($root);
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

it('extracts Artisan signatures from Schedule event command strings', function (): void {
    $method = new ReflectionMethod(PlatformAsyncEntryPointInventory::class, 'signatureFromScheduleEvent');

    $artisan = new class
    {
        public $command = "'/usr/bin/php' 'artisan' perf:prune";
    };
    $bare = new class
    {
        public $command = 'blb:workflow:reconcile';
    };
    $described = new class
    {
        public $command = '';

        public $description = 'blb:ai:schedules:tick';
    };
    $closure = new class
    {
        public $command = 'php -r "echo 1;"';
    };
    $empty = new class
    {
        public $command = '';
    };

    expect($method->invoke(null, $artisan))->toBe('perf:prune')
        ->and($method->invoke(null, $bare))->toBe('blb:workflow:reconcile')
        ->and($method->invoke(null, $described))->toBe('blb:ai:schedules:tick')
        ->and($method->invoke(null, $closure))->toBeNull()
        ->and($method->invoke(null, $empty))->toBeNull();
});

it('lists live Schedule signatures matching the Base/Core declaration by default', function (): void {
    app()->make(Kernel::class)->bootstrap();

    expect(PlatformAsyncEntryPointInventory::liveScheduledCommandSignatures())
        ->toEqualCanonicalizing(PlatformAsyncEntryPointInventory::scheduledCommandSignatures());

    // A root that contains no command classes drops every resolvable platform signature.
    expect(PlatformAsyncEntryPointInventory::liveScheduledCommandSignatures([
        storage_path('framework/testing/no-schedule-root-'.uniqid()),
    ]))->toBe([]);
});

it('keeps a live Schedule signature that resolves to no registered command', function (): void {
    app()->make(Kernel::class)->bootstrap();
    app(Schedule::class)->command('zz-unregistered:probe')->daily();

    expect(PlatformAsyncEntryPointInventory::liveScheduledCommandSignatures())
        ->toContain('zz-unregistered:probe');
});
