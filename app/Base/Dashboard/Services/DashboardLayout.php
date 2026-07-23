<?php

namespace App\Base\Dashboard\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\WidgetRegistry;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Support\Collection;

/**
 * Resolves the dashboard widget layout for a user.
 *
 * Precedence mirrors LandingPageResolver:
 *  1. The user's own `ui.dashboard.layout` setting — an ordered list of widget
 *     ids. Ids pointing at widgets the user can no longer see (revoked
 *     capability, uninstalled module) are skipped silently instead of
 *     erroring every visit.
 *  2. The default layout: visible widgets in registry order, capped.
 *
 * An explicitly saved empty layout is respected — it means "no widgets",
 * not "give me the default".
 */
class DashboardLayout
{
    public const SETTING_KEY = 'ui.dashboard.layout';

    /**
     * Widgets shown by default before the user personalizes.
     */
    public const DEFAULT_LIMIT = 6;

    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly AuthorizationService $authz,
        private readonly SettingsService $settings,
    ) {}

    /**
     * All widgets the user is allowed to see, in registry order.
     *
     * @return Collection<string, WidgetDefinition>
     */
    public function visibleFor(mixed $user): Collection
    {
        return $this->registry->all()->filter(
            fn (WidgetDefinition $widget): bool => $widget->permission === null
                || $this->authz->can(Actor::forUser($user), $widget->permission)->allowed,
        );
    }

    /**
     * The user's effective dashboard layout.
     *
     * @return list<WidgetDefinition>
     */
    public function layoutFor(mixed $user): array
    {
        $visible = $this->visibleFor($user);
        $saved = $this->savedIds($user);

        if ($saved === null) {
            return $visible->take(self::DEFAULT_LIMIT)->values()->all();
        }

        return collect($saved)
            ->filter(fn (string $id): bool => $visible->has($id))
            ->unique()
            ->map(fn (string $id): WidgetDefinition => $visible[$id])
            ->values()
            ->all();
    }

    /**
     * Whether the user has saved a personal layout.
     */
    public function hasCustomLayout(mixed $user): bool
    {
        return $this->savedIds($user) !== null;
    }

    /**
     * Persist the user's layout as a whole ordered list of widget ids.
     *
     * Ids are filtered to widgets currently visible to the user so a stale
     * or forged id never persists.
     *
     * @param  list<string>  $ids
     */
    public function save(mixed $user, array $ids): void
    {
        $visible = $this->visibleFor($user);

        $ids = collect($ids)
            ->filter(fn (mixed $id): bool => is_string($id) && $visible->has($id))
            ->unique()
            ->values()
            ->all();

        $this->settings->set(self::SETTING_KEY, $ids, $this->scopeFor($user));
    }

    /**
     * Remove the personal layout, restoring the default.
     */
    public function reset(mixed $user): void
    {
        $this->settings->forget(self::SETTING_KEY, $this->scopeFor($user));
    }

    /**
     * The saved widget id list, or null when the user has no layout pref.
     *
     * @return list<string>|null
     */
    private function savedIds(mixed $user): ?array
    {
        $scope = $this->scopeFor($user);

        if (! $this->settings->has(self::SETTING_KEY, $scope)) {
            return null;
        }

        $saved = $this->settings->get(self::SETTING_KEY, $scope);

        if (! is_array($saved)) {
            return null;
        }

        return collect($saved)->filter(fn (mixed $id): bool => is_string($id))->values()->all();
    }

    private function scopeFor(mixed $user): Scope
    {
        $userId = method_exists($user, 'getKey') ? $user->getKey() : null;

        if (! is_numeric($userId)) {
            throw new \InvalidArgumentException('Dashboard preferences require a persisted user.');
        }

        $companyId = method_exists($user, 'getCompanyId') ? $user->getCompanyId() : null;

        return Scope::user(
            (int) $userId,
            is_numeric($companyId) ? (int) $companyId : null,
        );
    }
}
