<?php

namespace App\Base\Foundation\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Menu\Contracts\NavigableMenuSnapshot;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;

/**
 * Resolves where a user lands after login (and on `/`).
 *
 * Precedence:
 *  1. The user's own `ui.landing_menu_id` setting — any navigable
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
    public const SETTING_KEY = 'ui.landing_menu_id';

    public function __construct(
        private readonly NavigableMenuSnapshot $menu,
        private readonly AuthorizationService $authz,
        private readonly DomainInstaller $installer,
        private readonly SettingsService $settings,
    ) {}

    public function urlFor(mixed $user): string
    {
        $options = $this->optionsFor($user);

        $userId = method_exists($user, 'getKey') ? $user->getKey() : null;
        $companyId = method_exists($user, 'getCompanyId') ? $user->getCompanyId() : null;
        $preference = is_numeric($userId)
            ? $this->settings->get(
                self::SETTING_KEY,
                Scope::user((int) $userId, is_numeric($companyId) ? (int) $companyId : null),
            )
            : null;

        if (is_string($preference) && isset($options[$preference]['href'])) {
            return $options[$preference]['href'];
        }

        if (! $this->installer->hasAnyInstalled() && $this->canViewBusinessDomains($user)) {
            return route('admin.system.software.modules.index', absolute: false);
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

    private function canViewBusinessDomains(mixed $user): bool
    {
        return $this->authz
            ->can(Actor::forUser($user), 'admin.system.software.modules.view')
            ->allowed;
    }
}
