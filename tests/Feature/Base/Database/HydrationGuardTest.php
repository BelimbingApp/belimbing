<?php

use App\Base\Database\Enums\HydrationGuardMode;
use App\Base\Database\Exceptions\HydrationLimitExceededException;
use App\Base\Database\Services\HydrationGuard;
use App\Base\Integration\Services\OutboundExchangePruner;
use App\Base\Schedule\Models\ScheduleRun;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Component;
use Livewire\Livewire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class HydrationGuardProbeJob implements ShouldQueue
{
    public function handle(): void
    {
        ScheduleRun::query()->get();
    }
}

final class HydrationGuardProbeComponent extends Component
{
    public function mount(): void
    {
        ScheduleRun::query()->get();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

function seedScheduleRuns(int $count): void
{
    $now = now();

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table('base_schedule_runs')->insert(array_map(static fn (int $i): array => [
            'source' => 'scheduler',
            'key' => "task-{$i}",
            'name' => "Task {$i}",
            'status' => 'succeeded',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

beforeEach(function (): void {
    seedScheduleRuns(app(HydrationGuard::class)->limit() + 1);
});

it('runs in throw mode under the testing environment', function (): void {
    $guard = app(HydrationGuard::class);

    expect($guard->mode())->toBe(HydrationGuardMode::Throw)
        ->and($guard->limit())->toBe((int) config('hydration_guard.limit'));
});

it('fails an unbounded Eloquent load from inside the load', function (): void {
    $guard = app(HydrationGuard::class);

    $limit = $guard->limit();

    expect(fn () => ScheduleRun::query()->get())
        ->toThrow(HydrationLimitExceededException::class, "process hydrated more than {$limit} Eloquent models (".ScheduleRun::class.': '.($limit + 1).')');
});

it('leaves bounded loads alone', function (): void {
    $guard = app(HydrationGuard::class);

    $runs = ScheduleRun::query()->limit($guard->limit())->get();

    expect($runs)->toHaveCount($guard->limit());
});

it('names the Livewire component that loaded too much', function (): void {
    try {
        Livewire::test(HydrationGuardProbeComponent::class);
        $this->fail('expected the guard to throw');
    } catch (Throwable $thrown) {
        // Livewire re-throws mount failures wrapped in a ViewException.
        $exception = $thrown instanceof HydrationLimitExceededException ? $thrown : $thrown->getPrevious();

        expect($exception)->toBeInstanceOf(HydrationLimitExceededException::class)
            ->and($exception->context['livewire'])->toBe('hydration-guard-probe-component')
            ->and($exception->getMessage())->toContain('[Livewire hydration-guard-probe-component]');
    }
});

it('names the queued job that loaded too much', function (): void {
    // The sync queue driver raises the same JobProcessing/JobProcessed events
    // as a worker. dispatch() runs the job when its pending dispatch is
    // destroyed, so that must happen inside the expected callback.
    expect(function (): void {
        dispatch(new HydrationGuardProbeJob);
    })
        ->toThrow(HydrationLimitExceededException::class, 'job '.HydrationGuardProbeJob::class);
});

it('names the console command that loaded too much', function (): void {
    $guard = app(HydrationGuard::class);

    // The console kernel only bridges Symfony command events to CommandStarting
    // outside unit tests, so drive the Laravel events the way a real run does.
    $input = new ArrayInput([]);
    $output = new NullOutput;

    Event::dispatch(new CommandStarting('blb-test:hydrate-runs', $input, $output));

    expect(fn () => ScheduleRun::query()->get())
        ->toThrow(HydrationLimitExceededException::class, 'command blb-test:hydrate-runs hydrated');

    Event::dispatch(new CommandFinished('blb-test:hydrate-runs', $input, $output, 1));

    expect($guard->hydrated())->toBe(0);
});

it('lets a deliberate bulk pass through when suspended', function (): void {
    $guard = app(HydrationGuard::class);

    $runs = $guard->suspend(fn () => ScheduleRun::query()->get());

    expect($runs)->toHaveCount($guard->limit() + 1);
});

it('lets the outbound payload pruner stream past the limit', function (): void {
    $count = app(HydrationGuard::class)->limit() + 1;
    $occurredAt = now()->subYear();

    foreach (array_chunk(range(1, $count), 500) as $chunk) {
        DB::table('base_integration_outbound_exchanges')->insert(array_map(static fn (int $i): array => [
            'id' => sprintf('hydration-guard-%05d', $i),
            'system' => 'example',
            'operation' => 'example.operation',
            'endpoint' => 'https://example.test/things',
            'request_body' => '{"kind":"json","value":{}}',
            'outcome' => 'success',
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ], $chunk));
    }

    expect(app(OutboundExchangePruner::class)->prunePayloads())->toBe($count)
        ->and(DB::table('base_integration_outbound_exchanges')->whereNotNull('request_body')->count())->toBe(0);
});
