<?php

namespace App\Base\Tenancy\Support;

use App\Base\Foundation\ApplicationTopology;
use App\Base\Pdf\Jobs\RenderPdfJob;
use App\Base\Schedule\Jobs\RunScheduledTaskJob;
use App\Base\Tenancy\Exceptions\PlatformAsyncEntryPointInventoryException;
use App\Core\AI\Jobs\CompactAgentMemoryJob;
use App\Core\AI\Jobs\DispatchDueSchedulesJob;
use App\Core\AI\Jobs\IndexAgentMemoryJob;
use App\Core\AI\Jobs\ProcessInboundSignalJob;
use App\Core\AI\Jobs\RunAgentTaskJob;
use App\Core\AI\Jobs\RunBackgroundCommandJob;
use App\Core\AI\Jobs\RunChatTurnJob;
use App\Core\AI\Jobs\RunHeadlessCliTaskJob;
use App\Core\AI\Jobs\RunLaraTaskProfileJob;
use App\Core\AI\Jobs\SpawnAgentSessionJob;
use App\Core\Geonames\Jobs\ImportPostcodes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use SplFileInfo;

/**
 * Declared inventory of platform (Base + Core) queued jobs and scheduled
 * Artisan commands that must carry or restore tenant context correctly.
 *
 * Domains and Extensions own their own entry-point proofs. This list is the
 * platform contract: discovery must match it so a new job cannot ship without
 * an explicit tenancy decision.
 */
final class PlatformAsyncEntryPointInventory
{
    /**
     * Queued job classes under Base and Core.
     *
     * @return list<class-string<ShouldQueue>>
     */
    public static function queuedJobClasses(): array
    {
        return [
            RenderPdfJob::class,
            RunScheduledTaskJob::class,
            CompactAgentMemoryJob::class,
            DispatchDueSchedulesJob::class,
            IndexAgentMemoryJob::class,
            ProcessInboundSignalJob::class,
            RunAgentTaskJob::class,
            RunBackgroundCommandJob::class,
            RunChatTurnJob::class,
            RunHeadlessCliTaskJob::class,
            RunLaraTaskProfileJob::class,
            SpawnAgentSessionJob::class,
            ImportPostcodes::class,
        ];
    }

    /**
     * Artisan command signatures registered on the Laravel scheduler from
     * Base/Core service providers (platform maintenance — no ambient tenant).
     *
     * @return list<string>
     */
    public static function scheduledCommandSignatures(): array
    {
        return [
            'perf:prune',
            'blb:software:inventory:warm',
            'blb:workflow:reconcile',
            'blb:integration:payloads:prune',
            'blb:ai:schedules:tick',
            'blb:ai:runs:reap-orphans',
            'blb:ai:turns:sweep-stale',
            'blb:ai:pricing:refresh',
        ];
    }

    /**
     * Build a dispatchable instance for each queued job so propagation can be
     * exercised without domain fixtures. Handles may no-op or throw; the
     * tenancy contract is the JobProcessing restore from the stamped payload.
     *
     * @return list<array{0: class-string<ShouldQueue>, 1: ShouldQueue}>
     */
    public static function dispatchableQueuedJobs(): array
    {
        return [
            [RenderPdfJob::class, new RenderPdfJob(view: 'welcome', data: [])],
            [RunScheduledTaskJob::class, new RunScheduledTaskJob(key: 'tenant-audit-probe')],
            [CompactAgentMemoryJob::class, new CompactAgentMemoryJob(employeeId: 0)],
            [DispatchDueSchedulesJob::class, new DispatchDueSchedulesJob],
            [IndexAgentMemoryJob::class, new IndexAgentMemoryJob(employeeId: 0)],
            [ProcessInboundSignalJob::class, new ProcessInboundSignalJob(
                channel: 'tenant-audit',
                requestData: [],
                requestHeaders: [],
                requestMethod: 'POST',
                requestUrl: 'https://example.test/tenant-audit',
            )],
            [RunAgentTaskJob::class, new RunAgentTaskJob(dispatchId: 'tenant-audit-dispatch')],
            [RunBackgroundCommandJob::class, new RunBackgroundCommandJob(dispatchId: 'tenant-audit-dispatch')],
            [RunChatTurnJob::class, new RunChatTurnJob(runId: 'tenant-audit-run')],
            [RunHeadlessCliTaskJob::class, new RunHeadlessCliTaskJob(dispatchId: 'tenant-audit-dispatch')],
            [RunLaraTaskProfileJob::class, new RunLaraTaskProfileJob(dispatchId: 'tenant-audit-dispatch')],
            [SpawnAgentSessionJob::class, new SpawnAgentSessionJob(orchestrationSessionId: 'tenant-audit-session')],
            [ImportPostcodes::class, new ImportPostcodes(countryCodes: [])],
        ];
    }

    /**
     * Live Schedule signatures scoped to Base and Core (or `$roots`).
     *
     * Each event's Artisan signature is resolved through {@see Artisan::all()}
     * to a command class; only classes whose source file lies under the given
     * roots are kept. Closure/exec events with no resolvable class stay in the
     * list so the inventory still notices them. Domain and Extension schedules
     * therefore do not fail the platform comparison on a composed checkout.
     *
     * @param  list<string>|null  $roots
     * @return list<string>
     */
    public static function liveScheduledCommandSignatures(?array $roots = null): array
    {
        $roots = $roots ?? [ApplicationTopology::baseRoot(), ApplicationTopology::coreRoot()];
        $normalizedRoots = array_map(
            static fn (string $root): string => rtrim(str_replace('\\', '/', $root), '/').'/',
            $roots,
        );

        $commands = Artisan::all();
        $scheduled = [];

        foreach (app(Schedule::class)->events() as $event) {
            $signature = self::signatureFromScheduleEvent($event);
            if ($signature === null) {
                continue;
            }

            $instance = $commands[$signature] ?? null;
            if ($instance === null) {
                $scheduled[] = $signature;

                continue;
            }

            $path = (new ReflectionClass($instance))->getFileName();
            if ($path === false) {
                $scheduled[] = $signature;

                continue;
            }

            $normalizedPath = str_replace('\\', '/', $path);
            foreach ($normalizedRoots as $root) {
                if (str_starts_with($normalizedPath, $root)) {
                    $scheduled[] = $signature;
                    break;
                }
            }
        }

        $scheduled = array_values(array_unique($scheduled));
        sort($scheduled);

        return $scheduled;
    }

    private static function signatureFromScheduleEvent(object $event): ?string
    {
        $command = (string) ($event->command ?? '');
        if ($command === '' && isset($event->description)) {
            $command = (string) $event->description;
        }

        if (preg_match("/artisan['\"]?\s+(\S+)/", $command, $matches) === 1) {
            return $matches[1];
        }

        if ($command !== '' && ! str_contains($command, ' ')) {
            return $command;
        }

        return null;
    }

    /**
     * Discover ShouldQueue classes under Base and Core Jobs trees.
     *
     * @param  list<string>|null  $roots
     * @return list<class-string<ShouldQueue>>
     */
    public static function discoverQueuedJobClasses(?array $roots = null): array
    {
        $classes = [];

        foreach ($roots ?? [ApplicationTopology::baseRoot(), ApplicationTopology::coreRoot()] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $class = self::queuedJobClassFromFile($file);
                if ($class !== null) {
                    $classes[] = $class;
                }
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * @return class-string<ShouldQueue>|null
     */
    private static function queuedJobClassFromFile(SplFileInfo $file): ?string
    {
        if ($file->getExtension() !== 'php' || ! self::looksLikeJobPath($file)) {
            return null;
        }

        $class = self::classFromPath($file->getPathname());
        if ($class === null || ! class_exists($class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $usable = ! $reflection->isAbstract()
            && ! $reflection->isInterface()
            && ! $reflection->isTrait()
            && $reflection->implementsInterface(ShouldQueue::class);

        /** @var class-string<ShouldQueue> $class */
        return $usable ? $class : null;
    }

    private static function looksLikeJobPath(SplFileInfo $file): bool
    {
        $path = $file->getPathname();

        return str_contains($path, DIRECTORY_SEPARATOR.'Jobs'.DIRECTORY_SEPARATOR)
            || str_ends_with($file->getFilename(), 'Job.php');
    }

    /**
     * @return class-string|null
     */
    public static function classFromPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $marker = '/app/';
        $pos = strrpos($normalized, $marker);
        if ($pos === false) {
            return null;
        }

        $relative = substr($normalized, $pos + strlen($marker));

        return 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
    }

    /**
     * Human-readable inventory for PR / audit paste.
     */
    public static function markdown(): string
    {
        $jobs = self::queuedJobClasses();
        sort($jobs);
        $commands = self::scheduledCommandSignatures();
        sort($commands);

        $lines = [
            '### Platform queued jobs (Base + Core)',
            '',
        ];
        foreach ($jobs as $job) {
            $lines[] = '- `'.$job.'`';
        }
        $lines[] = '';
        $lines[] = '### Platform scheduled commands';
        $lines[] = '';
        foreach ($commands as $command) {
            $lines[] = '- `'.$command.'`';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>|null  $roots
     */
    public static function assertJobsMatchDiscovery(?array $roots = null): void
    {
        $declared = self::queuedJobClasses();
        sort($declared);
        $discovered = self::discoverQueuedJobClasses($roots);

        if ($declared !== $discovered) {
            throw new PlatformAsyncEntryPointInventoryException(
                "Platform queued-job inventory drifted.\nDeclared: "
                .implode(', ', $declared)
                ."\nDiscovered: "
                .implode(', ', $discovered),
            );
        }
    }
}
