<?php

use App\Base\Database\Enums\HydrationGuardMode;
use App\Base\Database\Exceptions\HydrationLimitExceededException;
use App\Base\Database\Middleware\GuardRequestHydration;
use App\Base\Database\Services\HydrationGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\AbstractLogger;

final class HydrationGuardTestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class HydrationGuardTestFailingLogger extends AbstractLogger
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('logging is broken');
    }
}

final class HydrationGuardTestModelA {}

final class HydrationGuardTestModelB {}

final class HydrationGuardTestJob {}

function hydrationGuard(HydrationGuardMode $mode, int $limit = 3, ?HydrationGuardTestLogger $logger = null): HydrationGuard
{
    return new HydrationGuard($limit, $mode, $logger ?? new HydrationGuardTestLogger);
}

function hydrate(HydrationGuard $guard, int $count, string $class = HydrationGuardTestModelA::class): void
{
    for ($i = 0; $i < $count; $i++) {
        $guard->recordRetrieved($class);
    }
}

it('throws once the innermost unit of work crosses the limit', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);

    hydrate($guard, 3);
    expect($guard->hydrated())->toBe(3);

    expect(fn () => hydrate($guard, 1))->toThrow(HydrationLimitExceededException::class, 'process hydrated more than 3 Eloquent models (HydrationGuardTestModelA: 4)');
});

it('names the models that were hydrated, largest first', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw, limit: 5);

    hydrate($guard, 2, HydrationGuardTestModelB::class);
    hydrate($guard, 3);

    try {
        hydrate($guard, 1);
        $this->fail('expected the guard to throw');
    } catch (HydrationLimitExceededException $exception) {
        expect($exception->context['models'])->toBe([
            HydrationGuardTestModelA::class => 4,
            HydrationGuardTestModelB::class => 2,
        ])
            ->and($exception->context['limit'])->toBe(5)
            ->and($exception->context['unit'])->toBe('process');
    }
});

it('logs one structured warning per unit of work and keeps counting', function (): void {
    $logger = new HydrationGuardTestLogger;
    $guard = hydrationGuard(HydrationGuardMode::Log, logger: $logger);

    hydrate($guard, 10);

    expect($guard->hydrated())->toBe(10)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('process hydrated more than 3')
        ->and($logger->records[0]['context']['models'])->toBe([HydrationGuardTestModelA::class => 4]);
});

it('never throws in log mode, even when logging itself fails', function (): void {
    $guard = new HydrationGuard(2, HydrationGuardMode::Log, new HydrationGuardTestFailingLogger);

    hydrate($guard, 5);

    expect($guard->hydrated())->toBe(5);
});

it('counts a queued job separately from the process that runs it', function (): void {
    $logger = new HydrationGuardTestLogger;
    $guard = hydrationGuard(HydrationGuardMode::Log, logger: $logger);
    $job = new HydrationGuardTestJob;

    hydrate($guard, 2);
    $guard->openJob($job, 'App\Jobs\Rebuild');
    hydrate($guard, 4);
    $guard->closeJob($job);
    $guard->closeJob($job);
    hydrate($guard, 1);

    expect($guard->hydrated())->toBe(3)
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('job App\Jobs\Rebuild hydrated more than 3')
        ->and($logger->records[0]['context']['unit'])->toBe('job');
});

it('reports the enclosing unit of work for nested jobs and commands', function (): void {
    $logger = new HydrationGuardTestLogger;
    $guard = hydrationGuard(HydrationGuardMode::Log, logger: $logger);

    $guard->openCommand('blb:nightly');
    $guard->openJob(new HydrationGuardTestJob, 'App\Jobs\Step');
    hydrate($guard, 4);

    expect($logger->records[0]['message'])->toContain('job App\Jobs\Step')
        ->and($logger->records[0]['context']['nested_in'])->toBe('command blb:nightly');
});

it('closes a command window only for the command that opened it', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);

    $guard->openCommand('outer');
    $guard->openCommand('inner');
    $guard->closeCommand('outer');
    hydrate($guard, 2);

    expect($guard->hydrated())->toBe(2);

    $guard->closeCommand('inner');
    expect($guard->hydrated())->toBe(0);

    $guard->closeCommand('outer');
    $guard->closeCommand('outer');
    expect($guard->hydrated())->toBe(0);
});

it('does not count a suspended bulk pass', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);

    $result = $guard->suspend(function () use ($guard): string {
        hydrate($guard, 100);

        return $guard->suspend(function () use ($guard): string {
            hydrate($guard, 100);

            return 'done';
        });
    });

    expect($result)->toBe('done')->and($guard->hydrated())->toBe(0);

    hydrate($guard, 3);
    expect(fn () => hydrate($guard, 1))->toThrow(HydrationLimitExceededException::class);
});

it('raises the limit for the duration of withLimit only', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);

    $guard->withLimit(10, fn () => hydrate($guard, 8));

    expect($guard->limit())->toBe(3)->and($guard->hydrated())->toBe(8);

    // Already over the restored limit: the next hydration reports.
    expect(fn () => hydrate($guard, 1))->toThrow(HydrationLimitExceededException::class);
});

it('restores the limit when the callback throws', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);

    expect(fn () => $guard->withLimit(10, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect($guard->limit())->toBe(3);
});

it('makes the request the unit of work and names it from the route facts', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);
    hydrate($guard, 3);

    $middleware = new GuardRequestHydration($guard);
    $request = Request::create('/admin/system/schedule', 'GET');

    try {
        $middleware->handle($request, function () use ($guard): Response {
            hydrate($guard, 4);

            return new Response;
        });
        $this->fail('expected the guard to throw');
    } catch (HydrationLimitExceededException $exception) {
        expect($exception->getMessage())->toContain('GET /admin/system/schedule hydrated more than 3')
            ->and($exception->context['unit'])->toBe('http')
            ->and($exception->context['path'])->toBe('/admin/system/schedule');
    }

    $middleware->terminate($request, new Response);
    expect($guard->hydrated())->toBe(0);
});

it('resolves a job name only when a report needs it', function (): void {
    $guard = hydrationGuard(HydrationGuardMode::Throw);
    $job = new HydrationGuardTestJob;
    $resolved = 0;

    $guard->openJob($job, function () use (&$resolved): string {
        $resolved++;

        return 'App\Jobs\Lazy';
    });
    hydrate($guard, 3);
    $guard->closeJob($job);

    expect($resolved)->toBe(0);

    $guard->openJob($job, function () use (&$resolved): string {
        $resolved++;

        return 'App\Jobs\Lazy';
    });

    expect(fn () => hydrate($guard, 4))->toThrow(HydrationLimitExceededException::class, 'job App\Jobs\Lazy');
    expect($resolved)->toBe(1);
});
