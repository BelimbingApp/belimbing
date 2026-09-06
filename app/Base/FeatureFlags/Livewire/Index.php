<?php

namespace App\Base\FeatureFlags\Livewire;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Livewire\Concerns\ChecksCapabilityAuthorization;
use App\Base\FeatureFlags\Exceptions\UndeclaredFeatureFlagException;
use App\Base\FeatureFlags\Services\FeatureFlagDeclarationInventory;
use App\Base\FeatureFlags\Services\FeatureFlagOverrideHistory;
use App\Base\FeatureFlags\Services\FeatureFlags;
use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator surface for per-tenant feature-flag overrides.
 *
 * Declared flags only: the registry refuses undeclared names, and this page
 * never invents flags. Toggle / clear write through FeatureFlags so audit
 * mutations land on FeatureFlagOverride rows.
 *
 * Does not call FeatureFlags::enabled() with a dynamic name — that would trip
 * FeatureFlagReadRule (static ownership). The blade passes the target boolean
 * from the already-resolved list row.
 */
class Index extends Component
{
    use ChecksCapabilityAuthorization;
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function toggle(string $flag, bool $enable, FeatureFlags $flags): void
    {
        $this->runIfCapable('admin.system.feature-flags.manage', function () use ($flag, $enable, $flags): void {
            try {
                $flags->override($flag, $enable);
                $this->notifySuccess(
                    $enable
                        ? __('Flag :flag overridden on for this tenant.', ['flag' => $flag])
                        : __('Flag :flag overridden off for this tenant.', ['flag' => $flag]),
                );
            } catch (UndeclaredFeatureFlagException $e) {
                $this->notifyError($e->getMessage());
            }
        });
    }

    public function clearOverride(string $flag, FeatureFlags $flags): void
    {
        $this->runIfCapable('admin.system.feature-flags.manage', function () use ($flag, $flags): void {
            try {
                $flags->clearOverride($flag);
                $this->notifySuccess(__('Flag :flag restored to its declared default.', ['flag' => $flag]));
            } catch (UndeclaredFeatureFlagException $e) {
                $this->notifyError($e->getMessage());
            }
        });
    }

    public function render(FeatureFlagDeclarationInventory $declarations, FeatureFlagOverrideHistory $history): View
    {
        $needle = strtolower(trim($this->search));
        $allDeclaredRows = $declarations->forCurrentTenant();
        $hasDeclarationConflicts = collect($allDeclaredRows)->contains('conflict', true);
        $declaredRows = collect($allDeclaredRows)
            ->when($needle !== '', fn ($collection) => $collection->filter(
                fn (array $row): bool => str_contains(strtolower($row['flag']), $needle)
                    || collect($row['declarations'])->contains(
                        fn (array $declaration): bool => str_contains(strtolower($declaration['module']), $needle)
                            || str_contains(strtolower($declaration['description']), $needle),
                    ),
            ))
            ->values()
            ->all();
        $rows = collect($declaredRows)
            ->reject(fn (array $row): bool => $row['conflict'])
            ->map(function (array $row): array {
                $declaration = $row['declarations'][0];

                return [
                    'flag' => $row['flag'],
                    'module' => $declaration['module'],
                    'description' => $declaration['description'],
                    'default' => $declaration['default'],
                    'enabled' => $row['override_enabled'] ?? $declaration['default'],
                    'overridden' => $row['overridden'],
                ];
            })
            ->all();

        return view('livewire.admin.system.feature-flags.index', [
            'rows' => $rows,
            'declaredRows' => $declaredRows,
            'hasDeclarationConflicts' => $hasDeclarationConflicts,
            'canManage' => ! $hasDeclarationConflicts && $this->actorCanManage(),
            'overrideHistory' => $history->forCurrentTenant(),
        ]);
    }

    private function actorCanManage(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.feature-flags.manage')
            ->allowed;
    }
}
