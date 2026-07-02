<?php

namespace App\Base\Menu\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\DTO\MenuLinkResolution;
use App\Base\Menu\MenuItem;
use App\Base\Menu\MenuRegistry;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

final class MenuStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    public function __construct(
        private readonly MenuRegistry $registry,
        private readonly MenuAccessChecker $menuAccessChecker,
        private readonly MenuRegistryLoader $loader,
        private readonly MenuLinkResolver $linkResolver,
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canInspectMenu($user)) {
            return [];
        }

        $this->loader->ensureLoaded();

        return $this->registry
            ->getAll()
            ->filter(fn (MenuItem $item): bool => $item->hasRoute())
            ->filter(fn (MenuItem $item): bool => $this->menuAccessChecker->canView($item, $user))
            ->map(fn (MenuItem $item): ?StatusBarDiagnostic => $this->diagnosticFor($item))
            ->filter()
            ->values();
    }

    private function diagnosticFor(MenuItem $item): ?StatusBarDiagnostic
    {
        $resolution = $this->linkResolver->resolve($item);

        if ($resolution->isResolved()) {
            return null;
        }

        return new StatusBarDiagnostic(
            id: 'menu.unresolvable-link.'.$item->id,
            severity: StatusVariant::Warning,
            source: __('Menu'),
            summary: __('Menu item hidden: :label', ['label' => $item->label]),
            detail: $this->detail($item, $resolution),
            target: Route::has('admin.system.menu-inspector.index')
                ? route('admin.system.menu-inspector.index')
                : null,
            metadata: $this->linkResolver->failureContext($item, $resolution),
        );
    }

    private function detail(MenuItem $item, MenuLinkResolution $resolution): string
    {
        $route = $item->route ?? __('none');
        $source = $item->sourceFile ?? __('unknown source');

        return match ($resolution->reason) {
            'missing_route' => __('The menu item references route ":route", but Laravel has no route with that name. Source: :source.', [
                'route' => $route,
                'source' => $source,
            ]),
            'url_generation_failed' => __('Laravel could not generate route ":route" for this menu item. The route may require parameters. Source: :source.', [
                'route' => $route,
                'source' => $source,
            ]),
            default => __('The menu item has no resolvable route or URL. Source: :source.', [
                'source' => $source,
            ]),
        };
    }

    private function canInspectMenu(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.menu-inspector.view')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
