<?php

namespace App\Base\System\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Page-weight triage: render every full-page Livewire component and rank it by
 * rendered HTML size, query count, and Livewire-component count (`wire:id` roots).
 *
 * Used to triage client-side render cost (docs/plans/performance-page-rendering.md)
 * and, with --max-kb --strict, as a regression guardrail. Dev-only: it logs in a
 * user and renders pages, so it refuses to run in production.
 */
#[AsCommand(name: 'blb:perf:page-weights')]
class PageWeightAuditCommand extends Command
{
    protected $description = 'Rank full-page Livewire components by rendered HTML weight and query count';

    protected $signature = 'blb:perf:page-weights
                            {--max-kb= : Flag pages whose rendered HTML exceeds this many KB}
                            {--strict : Exit non-zero if any page exceeds --max-kb (CI guardrail)}
                            {--allow=* : Page URIs exempted from --strict (known/accepted over-budget pages)}
                            {--limit=40 : Number of rows to print}';

    public function handle(): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('Refusing to run in production (it authenticates a user and renders pages).');

            return self::FAILURE;
        }

        $user = $this->renderUser();

        if ($user === null) {
            $this->components->error('No user found to render pages as.');

            return self::FAILURE;
        }

        Auth::login($user);

        $rows = $this->pageWeightRows();
        $rendered = $this->renderedRows($rows);
        $maxKb = $this->option('max-kb') !== null ? (float) $this->option('max-kb') : null;
        $allow = (array) $this->option('allow');
        $overBudget = $maxKb !== null
            ? array_filter($rendered, static fn (array $r): bool => $r['kb'] > $maxKb)
            : [];

        // Pages on the allowlist are reported but do not fail --strict (the ratchet).
        $unaccountedOverBudget = array_filter(
            $overBudget,
            static fn (array $r): bool => ! in_array($r['page'], $allow, true),
        );

        $this->writePageWeightTable($rendered, $maxKb);
        $this->writePageWeightSummary($rows, $rendered, $overBudget, $unaccountedOverBudget, $maxKb);

        return $this->strictExitCode($unaccountedOverBudget);
    }

    private function renderUser(): ?EloquentModel
    {
        /** @var class-string<EloquentModel> $userModel */
        $userModel = config('auth.providers.users.model');

        return $userModel::query()->whereNotNull('company_id')->first() ?? $userModel::query()->first();
    }

    /**
     * @return array<class-string<LivewireComponent>, array{kb: float|null, queries: int, components: int, page: string}>
     */
    private function pageWeightRows(): array
    {
        $rows = [];

        foreach ($this->getLaravel()['router']->getRoutes() as $route) {
            $class = $this->livewireComponentClass($route);

            if ($class === null || isset($rows[$class])) {
                continue;
            }

            $rows[$class] = $this->measureRoute($route, $class);
        }

        return $rows;
    }

    /**
     * @return class-string<LivewireComponent>|null
     */
    private function livewireComponentClass(Route $route): ?string
    {
        if (! in_array('GET', $route->methods(), true)) {
            return null;
        }

        if (preg_match('/\{[^?}]+\}/', $route->uri()) === 1) {
            return null;
        }

        $class = explode('@', $route->getActionName())[0];

        if (! class_exists($class) || ! is_subclass_of($class, LivewireComponent::class)) {
            return null;
        }

        return $class;
    }

    /**
     * @param  class-string<LivewireComponent>  $class
     * @return array{kb: float|null, queries: int, components: int, page: string}
     */
    private function measureRoute(Route $route, string $class): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $html = Livewire::test($class)->html();

            return [
                'kb' => round(strlen($html) / 1024, 1),
                'queries' => count(DB::getQueryLog()),
                'components' => substr_count($html, 'wire:id'),
                'page' => $route->uri(),
            ];
        } catch (Throwable) {
            return ['kb' => null, 'queries' => 0, 'components' => 0, 'page' => $route->uri()];
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * @param  array<class-string<LivewireComponent>, array{kb: float|null, queries: int, components: int, page: string}>  $rows
     * @return array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>
     */
    private function renderedRows(array $rows): array
    {
        $rendered = array_filter($rows, static fn (array $row): bool => $row['kb'] !== null);
        uasort($rendered, static fn (array $a, array $b): int => $b['kb'] <=> $a['kb']);

        return $rendered;
    }

    /**
     * @param  array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>  $rendered
     */
    private function writePageWeightTable(array $rendered, ?float $maxKb): void
    {
        $table = [];

        foreach (array_slice($rendered, 0, (int) $this->option('limit'), true) as $row) {
            $flag = ($maxKb !== null && $row['kb'] > $maxKb) ? ' ⚠' : '';
            $table[] = [$row['kb'].$flag, $row['queries'], $row['components'], $row['page']];
        }

        $this->table(['KB', 'Queries', 'Components', 'Page'], $table);
    }

    /**
     * @param  array<class-string<LivewireComponent>, array{kb: float|null, queries: int, components: int, page: string}>  $rows
     * @param  array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>  $rendered
     * @param  array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>  $overBudget
     * @param  array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>  $unaccountedOverBudget
     */
    private function writePageWeightSummary(array $rows, array $rendered, array $overBudget, array $unaccountedOverBudget, ?float $maxKb): void
    {
        $this->components->info(sprintf(
            '%d rendered, %d skipped/errored.',
            count($rendered),
            count($rows) - count($rendered),
        ));

        if ($maxKb === null) {
            return;
        }

        $this->components->info(sprintf(
            '%d page(s) over %s KB (%d allowlisted).',
            count($overBudget),
            $maxKb,
            count($overBudget) - count($unaccountedOverBudget),
        ));
    }

    /**
     * @param  array<class-string<LivewireComponent>, array{kb: float, queries: int, components: int, page: string}>  $unaccountedOverBudget
     */
    private function strictExitCode(array $unaccountedOverBudget): int
    {
        if (! $this->option('strict') || $unaccountedOverBudget === []) {
            return self::SUCCESS;
        }

        foreach ($unaccountedOverBudget as $row) {
            $this->components->error(sprintf('%s is %s KB, over the %s KB budget.', $row['page'], $row['kb'], $this->option('max-kb')));
        }

        return self::FAILURE;
    }
}
