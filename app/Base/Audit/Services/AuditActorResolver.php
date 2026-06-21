<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Contracts\Auth\Authenticatable;

final class AuditActorResolver
{
    public function __construct(
        private readonly RequestContext $context,
    ) {}

    /**
     * @return array{type: string, id: int, company_id: int|null, name: string|null, email: string|null}
     */
    public function currentActor(): array
    {
        $user = auth()->user();

        if ($user instanceof Authenticatable) {
            return $this->authenticatedActor($user);
        }

        return [
            'type' => $this->context->actorType ?? PrincipalType::GUEST->value,
            'id' => $this->context->actorId ?? 0,
            'company_id' => $this->context->companyId,
            'name' => null,
            'email' => null,
        ];
    }

    /**
     * @return array{type: string, id: int, company_id: int|null, name: string|null, email: string|null}
     */
    private function authenticatedActor(Authenticatable $user): array
    {
        $companyId = $this->context->companyId;
        if ($companyId === null && method_exists($user, 'getCompanyId')) {
            $companyId = $user->getCompanyId();
        }

        return [
            'type' => method_exists($user, 'principalType')
                ? $user->principalType()->value
                : PrincipalType::USER->value,
            'id' => (int) $user->getAuthIdentifier(),
            'company_id' => $companyId,
            'name' => $this->stringOrNull(data_get($user, 'name')),
            'email' => $this->stringOrNull(data_get($user, 'email')),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
