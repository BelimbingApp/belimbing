<?php
namespace App\Base\Menu\Services;

use Illuminate\Contracts\Auth\Authenticatable;

class MenuConditionRegistry
{
    /**
     * @var array<string, \Closure(Authenticatable): bool>
     */
    private array $conditions = [];

    /**
     * Register a named visibility condition.
     *
     * @param  \Closure(Authenticatable): bool  $resolver
     */
    public function register(string $key, \Closure $resolver): void
    {
        $this->conditions[$key] = $resolver;
    }

    /**
     * Evaluate a named visibility condition.
     */
    public function allows(?string $key, Authenticatable $user): bool
    {
        if ($key === null) {
            return true;
        }

        $resolver = $this->conditions[$key] ?? null;

        return $resolver !== null ? (bool) $resolver($user) : false;
    }
}
