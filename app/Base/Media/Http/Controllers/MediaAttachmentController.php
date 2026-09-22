<?php

namespace App\Base\Media\Http\Controllers;

use App\Base\Authz\DTO\Actor;
use App\Base\Media\Models\MediaAttachment;
use App\Base\Media\Services\AttachmentSubjectAuthorizerRegistry;
use App\Base\Tenancy\Contracts\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class MediaAttachmentController
{
    public function __construct(
        private TenantContext $tenants,
        private AttachmentSubjectAuthorizerRegistry $authorizers,
    ) {}

    public function __invoke(Request $request, string $attachment): StreamedResponse
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $tenantId = $this->tenants->requireTenantId();
        $reference = MediaAttachment::query()
            ->where('tenant_id', $tenantId)
            ->where('public_id', $attachment)
            ->with(['mediaAsset', 'subject'])
            ->firstOrFail();

        $subject = $reference->subject;
        if (! $subject instanceof Model) {
            abort(404);
        }

        $actor = Actor::forUser($user);
        $this->authorizers->for($subject)->authorizeDownload($actor, $subject, $reference);

        $asset = $reference->mediaAsset;
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->storage_key), 404);

        $filename = $asset->original_filename ?? basename($asset->storage_key);

        return $disk->download($asset->storage_key, $filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
