<?php

namespace App\Base\Dashboard\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Dashboard\DTO\WidgetDefinition;
use App\Base\Dashboard\WidgetRegistry;
use Illuminate\Support\Collection;

/**
 * Resolves the dashboard widget layout for a user.
 *
 * Precedence mirrors LandingPageResolver:
 *  1. The user's own layout (prefs.dashboard) — an ordered list of widget
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
    public const PREF_KEY = 'dashboard';

    /**
     * Widgets shown by default before the user personalizes.
     */
    public const DEFAULT_LIMIT = 6;

    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly AuthorizationService $authz,
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

        $prefs = $user->prefsArray();
        $prefs[self::PREF_KEY] = $ids;

        $user->prefs = $prefs;
        $user->save();
    }

    /**
     * Remove the personal layout, restoring the default.
     */
    public function reset(mixed $user): void
    {
        $prefs = $user->prefsArray();
        unset($prefs[self::PREF_KEY]);

        $user->prefs = $prefs === [] ? null : $prefs;
        $user->save();
    }

    /**
     * The saved widget id list, or null when the user has no layout pref.
     *
     * @return list<string>|null
     */
    private function savedIds(mixed $user): ?array
    {
        $saved = method_exists($user, 'prefsArray')
            ? ($user->prefsArray()[self::PREF_KEY] ?? null)
            : null;

        if (! is_array($saved)) {
            return null;
        }

        return collect($saved)->filter(fn (mixed $id): bool => is_string($id))->values()->all();
    }
}
