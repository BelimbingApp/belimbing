<?php

namespace App\Base\Media\Services;

use App\Base\Media\Contracts\AttachmentSubjectAuthorizer;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AttachmentSubjectAuthorizerRegistry
{
    /** @var array<string, AttachmentSubjectAuthorizer> */
    private array $authorizers = [];

    /**
     * Register the authorizer for a model class or its stable morph alias.
     */
    public function register(string $subjectType, AttachmentSubjectAuthorizer $authorizer): void
    {
        $this->authorizers[$subjectType] = $authorizer;
    }

    public function for(Model $subject): AttachmentSubjectAuthorizer
    {
        $authorizer = $this->authorizers[$subject->getMorphClass()]
            ?? $this->authorizers[$subject::class]
            ?? null;

        if ($authorizer === null) {
            throw new AccessDeniedHttpException('No attachment access policy is registered for this subject.');
        }

        return $authorizer;
    }
}
