<?php

namespace App\Base\Media\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Media\DTO\AttachmentRules;
use App\Base\Media\Models\MediaAttachment;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PrivateAttachmentStore
{
    private const STORAGE_DISK = 'local';

    private const STORAGE_DIRECTORY = 'attachments';

    public function __construct(
        private MediaAssetStore $assets,
        private TenantContext $tenants,
        private AttachmentSubjectAuthorizerRegistry $authorizers,
    ) {}

    public function upload(
        UploadedFile $file,
        Model $subject,
        Actor $actor,
        ?AttachmentRules $rules = null,
    ): MediaAttachment {
        $tenantId = $this->tenants->requireTenantId();
        $rules ??= new AttachmentRules;

        $this->validateUpload($file, $rules);
        $this->assertActorTenant($actor, $tenantId);
        $this->assertSubjectTenant($subject, $tenantId);
        $this->authorizers->for($subject)->authorizeUpload($actor, $subject);

        $asset = $this->assets->putUploadedFile(self::STORAGE_DISK, self::STORAGE_DIRECTORY, $file);

        try {
            return MediaAttachment::query()->create([
                'public_id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'media_asset_id' => $asset->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (string) $subject->getKey(),
                'state' => MediaAttachment::STATE_DRAFT,
                'uploaded_by_type' => $actor->type,
                'uploaded_by_id' => $actor->id,
            ])->load('mediaAsset');
        } catch (\Throwable $exception) {
            $this->assets->delete($asset);
            throw $exception;
        }
    }

    /**
     * Atomically bind draft references as immutable submitted evidence.
     *
     * @param  iterable<string>  $publicIds
     * @return Collection<int, MediaAttachment>
     */
    public function submit(iterable $publicIds, Model $subject, Actor $actor): Collection
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->assertActorTenant($actor, $tenantId);
        $this->assertSubjectTenant($subject, $tenantId);
        $ids = collect($publicIds)->map(fn (mixed $id): string => (string) $id)->unique()->values();

        return DB::transaction(function () use ($ids, $subject, $actor, $tenantId): Collection {
            /** @var Collection<int, MediaAttachment> $attachments */
            $attachments = MediaAttachment::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('public_id', $ids)
                ->lockForUpdate()
                ->get();

            if ($attachments->count() !== $ids->count()) {
                throw ValidationException::withMessages(['attachments' => __('One or more attachments are unavailable.')]);
            }

            $this->authorizers->for($subject)->authorizeSubmit($actor, $subject, $attachments);

            foreach ($attachments as $attachment) {
                if ($attachment->subject_type !== $subject->getMorphClass()
                    || (string) $attachment->subject_id !== (string) $subject->getKey()) {
                    throw ValidationException::withMessages(['attachments' => __('One or more attachments belong to another record.')]);
                }

                if (! $attachment->isSubmitted()) {
                    $attachment->forceFill([
                        'state' => MediaAttachment::STATE_SUBMITTED,
                        'submitted_at' => now('UTC'),
                    ])->save();
                }
            }

            return $attachments->fresh(['mediaAsset']);
        });
    }

    public function discardDraft(MediaAttachment $attachment, ?Actor $actor = null): void
    {
        $tenantId = $this->tenants->requireTenantId();

        $asset = DB::transaction(function () use ($attachment, $actor, $tenantId) {
            $locked = MediaAttachment::query()
                ->whereKey($attachment->id)
                ->where('tenant_id', $tenantId)
                ->with('mediaAsset')
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->isSubmitted()) {
                throw new \LogicException('Only a current-tenant draft attachment may be discarded.');
            }
            if ($actor !== null
                && ($locked->uploaded_by_type !== $actor->type || $locked->uploaded_by_id !== $actor->id)) {
                throw new \LogicException('Only the draft uploader may discard this attachment.');
            }

            $asset = $locked->mediaAsset;
            $locked->delete();

            return $asset;
        });

        // Remove bytes only after the binding deletion commits. A rollback must
        // never leave a live attachment reference pointing at missing bytes.
        $this->assets->delete($asset);
    }

    public function cleanupDraftsBefore(Carbon $cutoff): int
    {
        $tenantId = $this->tenants->requireTenantId();
        $drafts = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('state', MediaAttachment::STATE_DRAFT)
            ->whereNull('retained_at')
            ->where('created_at', '<', $cutoff)
            ->with('mediaAsset')
            ->get();

        foreach ($drafts as $draft) {
            $this->discardDraft($draft);
        }

        return $drafts->count();
    }

    /** @param iterable<string> $publicIds */
    public function retainDrafts(iterable $publicIds, Model $subject, Actor $actor): void
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->assertActorTenant($actor, $tenantId);
        $this->assertSubjectTenant($subject, $tenantId);
        $ids = collect($publicIds)->map(fn (mixed $id): string => (string) $id)->unique()->values();

        DB::transaction(function () use ($ids, $subject, $actor, $tenantId): void {
            $attachments = MediaAttachment::query()->where('tenant_id', $tenantId)->whereIn('public_id', $ids)->lockForUpdate()->get();
            if ($attachments->count() !== $ids->count()) {
                throw ValidationException::withMessages(['attachments' => __('One or more attachments are unavailable.')]);
            }
            $this->authorizers->for($subject)->authorizeSubmit($actor, $subject, $attachments);
            foreach ($attachments as $attachment) {
                if ($attachment->subject_type !== $subject->getMorphClass() || (string) $attachment->subject_id !== (string) $subject->getKey()) {
                    throw ValidationException::withMessages(['attachments' => __('One or more attachments belong to another record.')]);
                }
                if (! $attachment->isSubmitted()) {
                    $attachment->forceFill(['retained_at' => now('UTC')])->save();
                }
            }
        });
    }

    private function validateUpload(UploadedFile $file, AttachmentRules $rules): void
    {
        $mime = strtolower(trim((string) $file->getMimeType()));
        $size = $file->getSize();

        $errors = [];

        if (! $file->isValid() || $size === false || $size < 1) {
            $errors[] = __('The upload did not complete. Please choose the file again.');
        } elseif ($size > $rules->maxBytes) {
            $errors[] = __('The file is too large.');
        }

        if ($mime === '' || ! in_array($mime, $rules->allowedMimeTypes, true)) {
            $errors[] = __('This file type is not allowed.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['attachment' => $errors]);
        }
    }

    private function assertActorTenant(Actor $actor, int $tenantId): void
    {
        if ($actor->tenantId !== $tenantId) {
            throw ValidationException::withMessages(['attachment' => __('The attachment actor is outside the current tenant.')]);
        }
    }

    private function assertSubjectTenant(Model $subject, int $tenantId): void
    {
        if (! array_key_exists('tenant_id', $subject->getAttributes())
            || (int) $subject->getAttribute('tenant_id') !== $tenantId) {
            throw ValidationException::withMessages(['attachment' => __('The attachment record is outside the current tenant.')]);
        }
    }
}
