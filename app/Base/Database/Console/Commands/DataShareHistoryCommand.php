<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Services\DataShare\DataShareHistoryQuery;
use App\Base\Tenancy\Services\PlatformOperatorTenantAccess;
use App\Core\User\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:db:share:history')]
class DataShareHistoryCommand extends Command
{
    protected $signature = 'blb:db:share:history
                            {--action= : Action class filter (offer|fetch|plan|apply|prune|export|failures)}
                            {--failures : Shorthand for --action=failures}
                            {--json : Emit machine-readable JSON}';

    protected $description = 'List Data Share ledger events (no payload values or secrets)';

    public function handle(DataShareHistoryQuery $history, PlatformOperatorTenantAccess $operator): int
    {
        if (! $operator->allows()) {
            $this->components->error('Data Share history requires the platform-operator tenant.');

            return self::FAILURE;
        }

        $action = $this->option('failures') ? 'failures' : trim((string) $this->option('action'));
        $action = $action === '' ? null : $action;
        $rows = $history->all($action);
        $actorNames = User::query()
            ->whereIn('id', $rows->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id')
            ->all();

        if ($this->option('json')) {
            $payload = $rows->map(static function ($event) use ($actorNames): array {
                return [
                    'id' => (int) $event->id,
                    'action' => $event->action,
                    'package_id' => $event->package_id,
                    'offer_id' => is_array($event->metadata) ? ($event->metadata['offer_id'] ?? null) : null,
                    'scope_name' => $event->scope_name,
                    'actor' => $event->actor_id === null ? null : ($actorNames[$event->actor_id] ?? null),
                    'error_summary' => $event->error_summary,
                    'created_at' => $event->created_at->toIso8601String(),
                    'metadata' => $event->metadata ?? [],
                ];
            })->values()->all();

            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->components->info('No Data Share history rows matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['Id', 'Action', 'Package / Offer', 'Scope', 'Actor', 'Error', 'When'],
            $rows->map(static function ($event) use ($actorNames): array {
                $offerId = is_array($event->metadata) ? ($event->metadata['offer_id'] ?? null) : null;

                return [
                    (string) $event->id,
                    $event->action,
                    $event->package_id ?? $offerId ?? '—',
                    $event->scope_name ?? '—',
                    $event->actor_id === null ? '—' : ($actorNames[$event->actor_id] ?? '—'),
                    $event->error_summary ?? '—',
                    $event->created_at->toIso8601String(),
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
