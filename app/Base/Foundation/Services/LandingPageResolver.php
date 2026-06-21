<?php

namespace App\Base\Foundation\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;

/**
 * Resolves where a user lands after login (and on `/`).
 *
 * Precedence:
 *  1. The user's own preference (prefs.landing_menu_id) — any navigable
 *     menu item still visible to them. A pref pointing at a page the user
 *     lost access to (revoked capability, uninstalled domain) falls through
 *     silently instead of 403ing every login.
 *  2. On an installation with no business domains yet, operators who can
 *     see the Business Domains screen land there — that screen is how a fresh
 *     install gets its domains.
 *  3. The dashboard.
 */
class LandingPageResolver
{
    public const PREF_KEY = 'landing_menu_id';

    public function __construct(
        private readonly NavigableMenuSnapshot $menu,
        private readonly AuthorizationService $authz,
        private readonly DomainInstaller $installer,
    ) {}

    public function urlFor(mixed $user): string
    {
        $options = $this->optionsFor($user);

        $preference = method_exists($user, 'prefsArray')
            ? ($user->prefsArray()[self::PREF_KEY] ?? null)
            : null;

        if (is_string($preference)) {
            $preference = $this->normalizePreference($preference);

            if (isset($options[$preference]['href'])) {
                return $options[$preference]['href'];
            }
        }

        if (! $this->installer->hasAnyInstalled() && $this->canViewBusinessDomains($user)) {
            return route('admin.system.software.business-domains.index', absolute: false);
        }

        return route('dashboard', absolute: false);
    }

    /**
     * Navigable menu items the user may pick as a landing page.
     *
     * @return array<string, array{label: string, pinLabel: string, icon: string, href: string|null, route: string|null}>
     */
    public function optionsFor(mixed $user): array
    {
        return $this->menu->snapshotForUser($user)['flat'];
    }

    private function normalizePreference(string $preference): string
    {
        return match ($preference) {
            'admin.system.domains' => 'admin.system.software.business-domain',
            'admin.system.update.belimbing' => 'admin.system.software.deployment',
            default => $preference,
        };
    }

    private function canViewBusinessDomains(mixed $user): bool
    {
        return $this->authz
            ->can(Actor::forUser($user), 'admin.system.software.business-domain.view')
            ->allowed;
    }
}
