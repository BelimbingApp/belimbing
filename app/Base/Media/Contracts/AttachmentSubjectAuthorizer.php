<?php

namespace App\Base\Media\Contracts;

use App\Base\Authz\DTO\Actor;
use App\Base\Media\Models\MediaAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface AttachmentSubjectAuthorizer
{
    public function authorizeUpload(Actor $actor, Model $subject): void;

    /** @param Collection<int, MediaAttachment> $attachments */
    public function authorizeSubmit(Actor $actor, Model $subject, Collection $attachments): void;

    public function authorizeDownload(Actor $actor, Model $subject, MediaAttachment $attachment): void;
}
