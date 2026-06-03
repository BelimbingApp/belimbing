<?php

namespace App\Base\System\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component as LivewireComponent;
use Livewire\Livewire;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Page-weight triage: render every full-page Livewire component and rank it by
 * rendered HTML size, query count, and nested-island count.
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
                            {--limit=40 : Number of rows to print}';

    public function handle(): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('Refusing to run in production (it authenticates a user and renders pages).');

            return self::FAILURE;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->whereNotNull('company_id')->first() ?? $userModel::query()->first();

        if ($user === null) {
            $this->components->error('No user found to render pages as.');

            return self::FAILURE;
        }

        Auth::login($user);

        $rows = [];
        foreach ($this->getLaravel()['router']->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            // Skip routes with required parameters — they need model binding we can't synthesize here.
            if (preg_match('/\{[^?}]+\}/', $route->uri()) === 1) {
                continue;
            }
            $class = explode('@', $route->getActionName())[0];
            if (! class_exists($class) || ! is_subclass_of($class, LivewireComponent::class) || isset($rows[$class])) {
                continue;
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $html = Livewire::test($class)->html();
                $rows[$class] = [
                    'kb' => round(strlen($html) / 1024, 1),
                    'queries' => count(DB::getQueryLog()),
                    'islands' => substr_count($html, 'wire:id'),
                    'page' => $route->uri(),
                ];
            } catch (Throwable $e) {
                $rows[$class] = ['kb' => null, 'queries' => 0, 'islands' => 0, 'page' => $route->uri()];
            }
            DB::disableQueryLog();
        }

        $rendered = array_filter($rows, static fn (array $r): bool => $r['kb'] !== null);
        uasort($rendered, static fn (array $a, array $b): int => $b['kb'] <=> $a['kb']);

        $maxKb = $this->option('max-kb') !== null ? (float) $this->option('max-kb') : null;
        $overBudget = $maxKb !== null
            ? array_filter($rendered, static fn (array $r): bool => $r['kb'] > $maxKb)
            : [];

        $table = [];
        foreach (array_slice($rendered, 0, (int) $this->option('limit'), true) as $r) {
            $flag = ($maxKb !== null && $r['kb'] > $maxKb) ? ' ⚠' : '';
            $table[] = [$r['kb'].$flag, $r['queries'], $r['islands'], $r['page']];
        }

        $this->table(['KB', 'Queries', 'Islands', 'Page'], $table);
        $this->components->info(sprintf(
            '%d rendered, %d skipped/errored.',
            count($rendered),
            count($rows) - count($rendered),
        ));

        if ($maxKb !== null) {
            $this->components->info(sprintf('%d page(s) over %s KB.', count($overBudget), $maxKb));

            if ($this->option('strict') && $overBudget !== []) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
