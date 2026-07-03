<?php

namespace Tests\Support;

use App\Base\Menu\Contracts\MenuAccessChecker;
use App\Base\Menu\MenuItem;
use Illuminate\Contracts\Auth\Authenticatable;

final class MenuTestFixtures
{
    /**
     * @return array<string, string>
     */
    public static function source(): array
    {
        return ['file' => 'tests/menu.php', 'module_name' => 'Tests'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function routeItem(string $id, string $label, string $route, ?string $permission = null): array
    {
        return array_filter([
            'id' => $id,
            'label' => $label,
            'route' => $route,
            'permission' => $permission,
            '_source' => self::source(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function urlItem(string $id, string $label, string $url): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'url' => $url,
            '_source' => self::source(),
        ];
    }

    public static function accessChecker(?string $hiddenId = null): MenuAccessChecker
    {
        return new class($hiddenId) implements MenuAccessChecker
        {
            public function __construct(
                private readonly ?string $hiddenId,
            ) {}

            public function canView(MenuItem $item, Authenticatable $user): bool
            {
                return $item->id !== $this->hiddenId;
            }
        };
    }
}
