<?php

use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-workspace-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

/**
 * @return array{user: User, supervised: Employee, unsupervised: Employee}
 */
function createSessionGuardFixture(): array
{
    $company = Company::factory()->create();

    $supervisor = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $otherSupervisor = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $supervisor->id,
    ]);

    $supervised = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'digital_worker',
        'supervisor_id' => $supervisor->id,
        'status' => 'active',
    ]);

    $unsupervised = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'digital_worker',
        'supervisor_id' => $otherSupervisor->id,
        'status' => 'active',
    ]);

    return [
        'user' => $user,
        'supervised' => $supervised,
        'unsupervised' => $unsupervised,
    ];
}

/**
 * Provision Lara and return two users that can interact with her.
 *
 * @return array{userA: User, userB: User}
 */
function createLaraFixture(): array
{
    Company::provisionLicensee('Test Company');
    Employee::provisionLara();

    $company = Company::query()->find(Company::LICENSEE_ID);

    $employeeA = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $employeeB = Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    $userA = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeA->id,
    ]);

    $userB = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeB->id,
    ]);

    return [
        'userA' => $userA,
        'userB' => $userB,
    ];
}

it('denies session access when user is not authenticated', function (): void {
    $fixture = createSessionGuardFixture();
    $sessionManager = new SessionManager;

    expect(fn () => $sessionManager->list($fixture['supervised']->id))
        ->toThrow(AuthorizationException::class);
});

it('denies listing sessions for unsupervised digital worker', function (): void {
    $fixture = createSessionGuardFixture();
    $this->actingAs($fixture['user']);

    $sessionManager = new SessionManager;

    expect(fn () => $sessionManager->list($fixture['unsupervised']->id))
        ->toThrow(AuthorizationException::class);
});

it('allows creating and listing sessions for supervised digital worker', function (): void {
    $fixture = createSessionGuardFixture();
    $this->actingAs($fixture['user']);

    $sessionManager = new SessionManager;
    $session = $sessionManager->create($fixture['supervised']->id);
    $sessions = $sessionManager->list($fixture['supervised']->id);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->id)->toBe($session->id);
});

it('denies message append for unsupervised digital worker', function (): void {
    $fixture = createSessionGuardFixture();
    $this->actingAs($fixture['user']);

    $messageManager = new MessageManager(new SessionManager);

    expect(fn () => $messageManager->appendUserMessage($fixture['unsupervised']->id, 'session-1', 'Hello'))
        ->toThrow(AuthorizationException::class);
});

it('allows message append and read for supervised digital worker sessions', function (): void {
    $fixture = createSessionGuardFixture();
    $this->actingAs($fixture['user']);

    $sessionManager = new SessionManager;
    $messageManager = new MessageManager($sessionManager);
    $session = $sessionManager->create($fixture['supervised']->id);

    $messageManager->appendUserMessage($fixture['supervised']->id, $session->id, 'Hello');
    $messageManager->appendAssistantMessage($fixture['supervised']->id, $session->id, 'Hi there');

    $messages = $messageManager->read($fixture['supervised']->id, $session->id);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->role)->toBe('assistant');
});

// --- Lara access strategy ---

it('denies Lara session access when user is not authenticated', function (): void {
    createLaraFixture();
    $sessionManager = new SessionManager;

    expect(fn () => $sessionManager->list(Employee::LARA_ID))
        ->toThrow(AuthorizationException::class);
});

it('allows any authenticated user to create and list Lara sessions', function (): void {
    $fixture = createLaraFixture();
    $this->actingAs($fixture['userA']);

    $sessionManager = new SessionManager;
    $session = $sessionManager->create(Employee::LARA_ID, 'Chat with Lara');
    $sessions = $sessionManager->list(Employee::LARA_ID);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]->id)->toBe($session->id)
        ->and($sessions[0]->title)->toBe('Chat with Lara');
});

it('isolates Lara sessions per user via path', function (): void {
    $fixture = createLaraFixture();

    // User A creates a session
    $this->actingAs($fixture['userA']);
    $sessionManager = new SessionManager;
    $sessionManager->create(Employee::LARA_ID, 'User A session');

    $pathA = $sessionManager->sessionsPath(Employee::LARA_ID);

    // User B sees an empty list (different path)
    $this->actingAs($fixture['userB']);
    $sessionManager = new SessionManager;
    $sessionsB = $sessionManager->list(Employee::LARA_ID);

    $pathB = $sessionManager->sessionsPath(Employee::LARA_ID);

    expect($sessionsB)->toHaveCount(0)
        ->and($pathA)->not->toBe($pathB)
        ->and($pathA)->toContain('/'.$fixture['userA']->id)
        ->and($pathB)->toContain('/'.$fixture['userB']->id);
});

it('allows message append and read for Lara sessions', function (): void {
    $fixture = createLaraFixture();
    $this->actingAs($fixture['userA']);

    $sessionManager = new SessionManager;
    $messageManager = new MessageManager($sessionManager);
    $session = $sessionManager->create(Employee::LARA_ID);

    $messageManager->appendUserMessage(Employee::LARA_ID, $session->id, 'Hello Lara');
    $messageManager->appendAssistantMessage(Employee::LARA_ID, $session->id, 'Hi!');

    $messages = $messageManager->read(Employee::LARA_ID, $session->id);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->role)->toBe('assistant');
});

it('does not leak Lara sessions across users via messages', function (): void {
    $fixture = createLaraFixture();

    // User A creates a session and appends a message
    $this->actingAs($fixture['userA']);
    $sessionManager = new SessionManager;
    $messageManager = new MessageManager($sessionManager);
    $session = $sessionManager->create(Employee::LARA_ID);
    $messageManager->appendUserMessage(Employee::LARA_ID, $session->id, 'Secret');

    // User B cannot see that session
    $this->actingAs($fixture['userB']);
    $sessionManager = new SessionManager;
    $messageManager = new MessageManager($sessionManager);

    expect($sessionManager->get(Employee::LARA_ID, $session->id))->toBeNull()
        ->and($messageManager->read(Employee::LARA_ID, $session->id))->toBeEmpty();
});
