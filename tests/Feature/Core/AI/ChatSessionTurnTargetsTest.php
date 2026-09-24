<?php

use App\Base\Database\Services\HydrationGuard;
use App\Core\AI\Enums\AiRunStatus;
use App\Core\AI\Enums\RunPhase;
use App\Core\AI\Livewire\Chat;
use App\Core\AI\Models\AiRun;
use App\Core\AI\Services\SessionManager;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/ai-turn-targets-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

function createTurnTargetsUser(): User
{
    provisionPlatformOperatorCompany('Test Company');
    Employee::provisionLara();

    $company = platformOperatorCompany();
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);

    return User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
}

function createTurnTargetsTurn(string $sessionId, int $userId, int $minutesAgo): AiRun
{
    $turn = AiRun::query()->forceCreate([
        'employee_id' => Employee::LARA_ID,
        'session_id' => $sessionId,
        'acting_for_user_id' => $userId,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'status' => AiRunStatus::Succeeded,
        'current_phase' => RunPhase::WaitingForWorker,
    ]);
    $turn->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

    return $turn;
}

it('targets the newest turn of each session, ranked in the database', function (): void {
    $user = createTurnTargetsUser();
    $other = User::factory()->create(['company_id' => $user->company_id]);
    $this->actingAs($user);

    $sessions = app(SessionManager::class);
    $first = $sessions->create(Employee::LARA_ID);
    $second = $sessions->create(Employee::LARA_ID);

    createTurnTargetsTurn($first->id, $user->id, 30);
    $newestFirst = createTurnTargetsTurn($first->id, $user->id, 5);
    createTurnTargetsTurn($first->id, $user->id, 20);
    createTurnTargetsTurn($first->id, $other->id, 1);
    $onlySecond = createTurnTargetsTurn($second->id, $user->id, 10);

    $chat = Livewire::test(Chat::class)->instance();
    $guard = app(HydrationGuard::class);
    $hydratedBefore = $guard->hydrated();

    $targets = (fn (): array => $this->sessionTurnTargets(
        [$first, $second],
        [$second->id => ['runId' => $onlySecond->id]],
    ))->call($chat);

    expect($targets)->toHaveCount(2)
        ->and($targets[$first->id])->toBe(['run_id' => $newestFirst->id, 'is_active' => false])
        ->and($targets[$second->id])->toBe(['run_id' => $onlySecond->id, 'is_active' => true])
        // The ranking returns plain rows: no turn model is hydrated to find the newest.
        ->and($guard->hydrated())->toBe($hydratedBefore);
});
