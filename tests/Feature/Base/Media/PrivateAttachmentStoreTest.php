<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Media\Contracts\AttachmentSubjectAuthorizer;
use App\Base\Media\DTO\AttachmentRules;
use App\Base\Media\Models\MediaAttachment;
use App\Base\Media\Services\AttachmentSubjectAuthorizerRegistry;
use App\Base\Media\Services\PrivateAttachmentStore;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function registerCompanyAttachmentAuthorizer(bool $allow = true): void
{
    app(AttachmentSubjectAuthorizerRegistry::class)->register(Company::class, new class($allow) implements AttachmentSubjectAuthorizer
    {
        public function __construct(private readonly bool $allow) {}

        public function authorizeUpload(Actor $actor, Model $subject): void
        {
            $this->authorize();
        }

        public function authorizeSubmit(Actor $actor, Model $subject, Collection $attachments): void
        {
            $this->authorize();
        }

        public function authorizeDownload(Actor $actor, Model $subject, MediaAttachment $attachment): void
        {
            $this->authorize();
        }

        private function authorize(): void
        {
            if (! $this->allow) {
                throw new AuthorizationException;
            }
        }
    });
}

function privateAttachmentFixture(): array
{
    Storage::fake('local');
    [$tenant, $company] = createTenantWithCompany();
    $user = User::factory()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set($tenant->id);
    registerCompanyAttachmentAuthorizer();

    return [$tenant, $company, $user, Actor::forUser($user)];
}

function attachmentPng(): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'evidence.png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    );
}

it('stores a validated private draft and freezes its submitted reference', function (): void {
    [, $company, , $actor] = privateAttachmentFixture();
    $store = app(PrivateAttachmentStore::class);

    $draft = $store->upload(attachmentPng(), $company, $actor);

    expect($draft->state)->toBe(MediaAttachment::STATE_DRAFT)
        ->and($draft->public_id)->toHaveLength(26)
        ->and($draft->mediaAsset->storage_key)->toStartWith('tenants/'.$company->tenant_id.'/attachments/');

    $submitted = $store->submit([$draft->public_id], $company, $actor)->firstOrFail();

    expect($submitted->state)->toBe(MediaAttachment::STATE_SUBMITTED)
        ->and($submitted->submitted_at)->not->toBeNull();

    expect(fn () => $submitted->forceFill(['subject_id' => '999'])->save())
        ->toThrow(LogicException::class);
    expect(fn () => $store->discardDraft($submitted))
        ->toThrow(LogicException::class);
});

it('rejects oversized, invalid-type, other-tenant and unauthorized uploads', function (): void {
    [$tenant, $company, , $actor] = privateAttachmentFixture();
    $store = app(PrivateAttachmentStore::class);

    expect(fn () => $store->upload(
        UploadedFile::fake()->createWithContent('large.txt', 'too large'),
        $company,
        $actor,
        new AttachmentRules(maxBytes: 2, allowedMimeTypes: ['text/plain']),
    ))->toThrow(ValidationException::class);

    expect(fn () => $store->upload(
        UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        $company,
        $actor,
    ))->toThrow(ValidationException::class);

    [, $otherCompany] = createTenantWithCompany();
    app(TenantContext::class)->set($tenant->id);
    expect(fn () => $store->upload(attachmentPng(), $otherCompany, $actor))
        ->toThrow(ValidationException::class);

    registerCompanyAttachmentAuthorizer(false);
    expect(fn () => $store->upload(attachmentPng(), $company, $actor))
        ->toThrow(AuthorizationException::class);
});

it('authorizes every download against tenant and subject', function (): void {
    [$tenant, $company, $user, $actor] = privateAttachmentFixture();
    $attachment = app(PrivateAttachmentStore::class)->upload(attachmentPng(), $company, $actor);
    app(PrivateAttachmentStore::class)->submit([$attachment->public_id], $company, $actor);

    $this->actingAs($user)
        ->get(route('media.attachments.download', $attachment->public_id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');

    [$otherTenant, $otherCompany] = createTenantWithCompany();
    $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
    app(TenantContext::class)->set($otherTenant->id);

    $this->actingAs($otherUser)
        ->get(route('media.attachments.download', $attachment->public_id))
        ->assertNotFound();

    app(TenantContext::class)->set($tenant->id);
    registerCompanyAttachmentAuthorizer(false);
    $this->actingAs($user)
        ->get(route('media.attachments.download', $attachment->public_id))
        ->assertForbidden();
});

it('cleans up only expired drafts and their bytes', function (): void {
    [, $company, , $actor] = privateAttachmentFixture();
    $store = app(PrivateAttachmentStore::class);
    $expired = $store->upload(attachmentPng(), $company, $actor);
    $current = $store->upload(attachmentPng(), $company, $actor);
    $expired->forceFill(['created_at' => now()->subDays(2)])->save();

    expect($store->cleanupDraftsBefore(now()->subDay()))->toBe(1)
        ->and(MediaAttachment::query()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(MediaAttachment::query()->whereKey($current->id)->exists())->toBeTrue();

    Storage::disk('local')->assertMissing($expired->mediaAsset->storage_key);
    Storage::disk('local')->assertExists($current->mediaAsset->storage_key);
});

it('does not discard a stale model after the attachment was submitted', function (): void {
    [, $company, , $actor] = privateAttachmentFixture();
    $store = app(PrivateAttachmentStore::class);
    $draft = $store->upload(attachmentPng(), $company, $actor);
    $stale = MediaAttachment::query()->findOrFail($draft->id);

    $store->submit([$draft->public_id], $company, $actor);

    expect(fn () => $store->discardDraft($stale, $actor))->toThrow(LogicException::class)
        ->and(MediaAttachment::query()->whereKey($draft->id)->value('state'))->toBe(MediaAttachment::STATE_SUBMITTED);
    Storage::disk('local')->assertExists($draft->mediaAsset->storage_key);
});
