<?php

namespace App\Base\Menu\Services;

use App\Base\Menu\DTO\MenuLinkResolution;
use App\Base\Menu\MenuItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

final class MenuLinkResolver
{
    private const LOG_DEDUP_SECONDS = 900;

    public function resolve(MenuItem $item): MenuLinkResolution
    {
        if ($item->url !== null) {
            return MenuLinkResolution::resolved($item->url);
        }

        if ($item->route === null) {
            return MenuLinkResolution::failed('missing_link');
        }

        if (! Route::has($item->route)) {
            return MenuLinkResolution::failed('missing_route');
        }

        try {
            return MenuLinkResolution::resolved($item->href());
        } catch (\Throwable $e) {
            return MenuLinkResolution::failed('url_generation_failed', $e->getMessage());
        }
    }

    public function logUnresolvable(MenuItem $item, MenuLinkResolution $resolution): void
    {
        if ($resolution->isResolved()) {
            return;
        }

        $context = $this->failureContext($item, $resolution);

        if (! $this->shouldLogUnresolvableLink($context)) {
            return;
        }

        logger()->warning('Menu item hidden because its link cannot be resolved.', $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function failureContext(MenuItem $item, MenuLinkResolution $resolution): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'route' => $item->route,
            'url' => $item->url,
            'source_module' => $item->sourceModule,
            'source_file' => $item->sourceFile,
        ] + $resolution->failureContext();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldLogUnresolvableLink(array $context): bool
    {
        $fingerprint = implode('|', array_map(
            static fn (mixed $value): string => is_scalar($value) || $value === null
                ? (string) $value
                : serialize($value),
            [
                'id' => $context['id'] ?? null,
                'route' => $context['route'] ?? null,
                'url' => $context['url'] ?? null,
                'source_file' => $context['source_file'] ?? null,
                'reason' => $context['reason'] ?? null,
                'error' => $context['error'] ?? null,
            ],
        ));

        try {
            return Cache::add(
                'blb.menu.unresolvable-link-log.'.sha1($fingerprint),
                true,
                now()->addSeconds(self::LOG_DEDUP_SECONDS),
            );
        } catch (\Throwable) {
            return true;
        }
    }
}
