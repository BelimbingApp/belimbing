<?php
namespace App\Base\Authz\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use Illuminate\Support\Collection;

interface AuthorizationService
{
    public function can(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = []
    ): AuthorizationDecision;

    public function authorize(
        Actor $actor,
        string $capability,
        ?ResourceContext $resource = null,
        array $context = []
    ): void;

    public function filterAllowed(
        Actor $actor,
        string $capability,
        iterable $resources,
        array $context = []
    ): Collection;
}
