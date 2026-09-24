<?php

namespace App\Base\Database\Services;

use App\Base\Database\Enums\HydrationGuardMode;
use App\Base\Database\Exceptions\HydrationLimitExceededException;
use Closure;
use Livewire\Livewire;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts the Eloquent models hydrated per unit of work and reacts once the
 * count crosses the configured limit. A unit of work is the innermost open
 * window: an HTTP request (including a Livewire update), a queued job, a
 * console command, or — outside all of those — the process itself, which is
 * what a Livewire::test() or plain unit test lands in.
 *
 * Counting happens on the Eloquent `retrieved` event, so anything that
 * suspends model events (Model::withoutEvents, an unset dispatcher) is not
 * counted; those are already deliberate bulk paths. chunk(), lazy() and
 * cursor() fire `retrieved` per row and are counted like everything else:
 * the guard measures work per unit, not peak memory, so streaming only
 * satisfies the guard inside suspend(), which is how a deliberate bulk pass
 * declares itself.
 *
 * In throw mode the crossing raises HydrationLimitExceededException from
 * inside the load, so the offending code path fails. In log mode it writes
 * one structured warning per window and never throws — the reporting itself
 * is wrapped so the guard can never fail a production request.
 *
 * A container singleton: Octane keeps it alive across requests, so
 * openRequest() resets state instead of relying on fresh construction.
 * See docs/architecture/query-bounds.md.
 */
final class HydrationGuard
{
    private const TOP_MODELS = 5;

    /** @var list<HydrationWindow> Innermost window last; index 0 is the process root. */
    private array $windows = [];

    private int $suspendDepth = 0;

    public function __construct(
        private readonly int $limit,
        private readonly HydrationGuardMode $mode,
        private readonly LoggerInterface $logger,
    ) {
        $this->reset();
    }

    public function limit(): int
    {
        return $this->limit;
    }

    public function mode(): HydrationGuardMode
    {
        return $this->mode;
    }

    /** Models hydrated so far in the innermost open window. */
    public function hydrated(): int
    {
        return $this->current()->count;
    }

    /** Drop every window and start over from a fresh process root. */
    public function reset(): void
    {
        $this->windows = [new HydrationWindow(HydrationWindow::KIND_PROCESS, null)];
    }

    /**
     * A request is always the outermost unit of work in its process, so
     * opening one discards whatever a previous request (Octane) or a
     * mis-balanced job or command left behind.
     *
     * @param  Closure(): array<string, mixed>  $describe  Request facts for the report, resolved lazily.
     */
    public function openRequest(Closure $describe): void
    {
        $this->reset();
        $this->windows[] = new HydrationWindow(HydrationWindow::KIND_HTTP, null, $describe);
    }

    public function closeRequest(): void
    {
        $this->closeIf(static fn (HydrationWindow $window): bool => $window->kind === HydrationWindow::KIND_HTTP);
    }

    /**
     * @param  string|(Closure(): string)  $name  Pass a closure so resolving the name costs nothing unless a report needs it.
     */
    public function openJob(object $job, string|Closure $name): void
    {
        $this->windows[] = new HydrationWindow(HydrationWindow::KIND_JOB, $name, null, spl_object_id($job));
    }

    /** Idempotent: a job that fails raises more than one closing event. */
    public function closeJob(object $job): void
    {
        $identity = spl_object_id($job);

        $this->closeIf(static fn (HydrationWindow $window): bool => $window->kind === HydrationWindow::KIND_JOB
            && $window->identity === $identity);
    }

    public function openCommand(string $name): void
    {
        $this->windows[] = new HydrationWindow(HydrationWindow::KIND_COMMAND, $name);
    }

    public function closeCommand(string $name): void
    {
        $this->closeIf(static fn (HydrationWindow $window): bool => $window->kind === HydrationWindow::KIND_COMMAND
            && $window->label() === $name);
    }

    /**
     * Hot path: one array increment per hydrated model. Everything beyond
     * that runs once per window, at the crossing.
     *
     * @param  class-string  $modelClass
     */
    public function recordRetrieved(string $modelClass): void
    {
        if ($this->suspendDepth > 0) {
            return;
        }

        $window = $this->current();
        $window->count++;
        $window->models[$modelClass] = ($window->models[$modelClass] ?? 0) + 1;

        if ($window->count > $this->limit && ! $window->reported) {
            $window->reported = true;
            $this->report($window);
        }
    }

    /**
     * Run a deliberate bulk pass without counting it. Nested calls stack.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function suspend(callable $callback): mixed
    {
        $this->suspendDepth++;

        try {
            return $callback();
        } finally {
            $this->suspendDepth--;
        }
    }

    private function current(): HydrationWindow
    {
        return $this->windows[array_key_last($this->windows)];
    }

    /** Pop the innermost window when it matches; the process root never pops. */
    private function closeIf(Closure $matches): void
    {
        if (count($this->windows) > 1 && $matches($this->current())) {
            array_pop($this->windows);
        }
    }

    private function report(HydrationWindow $window): void
    {
        if ($this->mode === HydrationGuardMode::Throw) {
            [$description, $models, $context] = $this->describe($window);

            throw HydrationLimitExceededException::forUnitOfWork($description, $this->limit, $models, $context);
        }

        try {
            [$description, $models, $context] = $this->describe($window);

            $this->logger->warning("Hydration guard: {$description} hydrated more than {$this->limit} Eloquent models ({$models}).", $context);
        } catch (Throwable) {
            // Log mode exists so the guard can never take a production request down.
        }
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>} Unit description, top models summary, log context.
     */
    private function describe(HydrationWindow $window): array
    {
        $facts = $window->describe !== null ? ($window->describe)() : [];
        $livewire = $this->currentLivewireComponent();
        $outer = count($this->windows) > 2 ? $this->windows[count($this->windows) - 2] : null;

        $description = match ($window->kind) {
            HydrationWindow::KIND_HTTP => sprintf('%s %s', $facts['method'] ?? 'HTTP', $facts['path'] ?? '?')
                .(isset($facts['route']) ? " (route {$facts['route']})" : ''),
            HydrationWindow::KIND_JOB => "job {$window->label()}",
            HydrationWindow::KIND_COMMAND => "command {$window->label()}",
            default => 'process',
        };

        if ($livewire !== null) {
            $description .= " [Livewire {$livewire}]";
        }

        $models = $window->models;
        arsort($models);
        $models = array_slice($models, 0, self::TOP_MODELS, true);

        $summary = implode(', ', array_map(
            static fn (string $class, int $count): string => "{$class}: {$count}",
            array_keys($models),
            $models,
        ));

        return [$description, $summary, array_filter([
            'unit' => $window->kind,
            'label' => $window->label(),
            'livewire' => $livewire,
            'nested_in' => $outer !== null && $outer->kind !== HydrationWindow::KIND_PROCESS
                ? "{$outer->kind} {$outer->label()}"
                : null,
            'limit' => $this->limit,
            'models' => $models,
            ...$facts,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    private function currentLivewireComponent(): ?string
    {
        try {
            return Livewire::current()?->getName();
        } catch (Throwable) {
            return null;
        }
    }
}
