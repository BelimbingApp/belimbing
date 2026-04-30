<?php

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const CHAT_ATTACHMENT_DOC_FILENAME = 'att_doc1_notes.txt';

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-chat-attachments-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

function createChatAttachmentsFixture(): User
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'attachment-test-provider',
        'display_name' => 'Attachment Test Provider',
        'base_url' => 'https://attachment-provider.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'test-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'attachment-test-model',
        'is_active' => true,
        'is_default' => true,
    ]);

    return createAdminUser();
}

function tinyPngFixture(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6n0AAAAASUVORK5CYII=',
        true,
    );
}

it('streams session attachments by attachment id', function (): void {
    test()->actingAs(createChatAttachmentsFixture());

    $sessionManager = app(SessionManager::class);
    $session = $sessionManager->create(Employee::LARA_ID);

    $dir = $sessionManager->sessionsPath(Employee::LARA_ID).'/attachments/'.$session->id;
    File::ensureDirectoryExists($dir);

    File::put($dir.'/att_img1_diagram.png', tinyPngFixture());
    File::put($dir.'/'.CHAT_ATTACHMENT_DOC_FILENAME, 'hello');

    $img = test()
        ->get(route('ai.chat.attachments.show', [
            'employeeId' => Employee::LARA_ID,
            'sessionId' => $session->id,
            'attachmentId' => 'att_img1',
        ]).'?mime=image%2Fpng');

    $img->assertOk();

    $doc = test()
        ->get(route('ai.chat.attachments.show', [
            'employeeId' => Employee::LARA_ID,
            'sessionId' => $session->id,
            'attachmentId' => 'att_doc1',
        ]).'?mime=text%2Fplain');

    $doc->assertOk();

    expect($img->baseResponse->headers->get('content-disposition'))
        ->toBe('inline')
        ->and($img->baseResponse->headers->get('content-type'))->toStartWith('image/png')
        ->and($img->baseResponse->getFile()->getPathname())->toBe($dir.'/att_img1_diagram.png');

    expect($doc->baseResponse->headers->get('content-disposition'))
        ->toBe('attachment')
        ->and($doc->baseResponse->headers->get('content-type'))->toStartWith('text/plain')
        ->and($doc->baseResponse->getFile()->getPathname())->toBe($dir.'/'.CHAT_ATTACHMENT_DOC_FILENAME);
});

it('returns 404 when the requested attachment id does not exist for the session', function (): void {
    test()->actingAs(createChatAttachmentsFixture());

    $session = app(SessionManager::class)->create(Employee::LARA_ID);

    $dir = app(SessionManager::class)->sessionsPath(Employee::LARA_ID).'/attachments/'.$session->id;
    File::ensureDirectoryExists($dir);
    File::put($dir.'/'.CHAT_ATTACHMENT_DOC_FILENAME, 'hello');

    test()
        ->get(route('ai.chat.attachments.show', [
            'employeeId' => Employee::LARA_ID,
            'sessionId' => $session->id,
            'attachmentId' => 'att_missing',
        ]).'?mime=text%2Fplain')
        ->assertNotFound();
});
