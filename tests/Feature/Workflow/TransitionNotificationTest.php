<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Workflow\Contracts\PresentsWorkflowNotifications;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionPayload;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Listeners\SendTransitionNotification;
use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusHistory;
use App\Base\Workflow\Models\StatusTransition;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('workflow_notification_test_models', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('company_id')->constrained('companies');
        $table->foreignId('reporter_id')->constrained('employees');
        $table->string('status');
        $table->string('title');
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('workflow_notification_test_models');
});

test('employee stakeholders receive one tenant-safe notification across durable retries', function (): void {
    $company = Company::factory()->create();
    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    $reporterUser = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $reporter->id,
    ]);
    $model = WorkflowNotificationTestModel::query()->create([
        'company_id' => $company->id,
        'reporter_id' => $reporter->id,
        'status' => 'done',
        'title' => 'Printer restored',
    ]);

    // Agent ids occupy a different identity namespace. Matching a User id
    // must not suppress that user's stakeholder notification.
    $actor = new Actor(
        type: PrincipalType::AGENT,
        id: $reporterUser->id,
        companyId: $company->id,
        actingForUserId: $reporterUser->id,
    );
    $event = workflowNotificationEvent($model, $actor);
    $listener = app(SendTransitionNotification::class);

    $listener->handle($event);
    $listener->handle($event);

    $notifications = $reporterUser->notifications()->get();

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->data)->toMatchArray([
            'title' => 'Printer restored',
            'url' => 'https://blb.test/workflow/'.$model->id,
            'actor_type' => PrincipalType::AGENT->value,
        ]);
});

test('employee resolution refuses a linked user from another company', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $reporter = Employee::factory()->create(['company_id' => $company->id]);
    $wrongUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $reporter->id,
    ]);
    $actorUser = User::factory()->create(['company_id' => $company->id]);
    $model = WorkflowNotificationTestModel::query()->create([
        'company_id' => $company->id,
        'reporter_id' => $reporter->id,
        'status' => 'done',
        'title' => 'Tenant boundary',
    ]);

    app(SendTransitionNotification::class)->handle(
        workflowNotificationEvent($model, Actor::forUser($actorUser)),
    );

    expect($wrongUser->notifications()->count())->toBe(0);
});

function workflowNotificationEvent(WorkflowNotificationTestModel $model, Actor $actor): TransitionCompleted
{
    StatusConfig::query()->create([
        'flow' => WorkflowNotificationTestModel::FLOW,
        'code' => 'done',
        'label' => 'Done',
        'notifications' => ['on_enter' => ['reporter'], 'channels' => ['database']],
        'position' => 1,
        'is_active' => true,
    ]);
    $transition = StatusTransition::query()->create([
        'flow' => WorkflowNotificationTestModel::FLOW,
        'from_code' => 'open',
        'to_code' => 'done',
        'label' => 'Finish',
        'position' => 1,
        'is_active' => true,
    ]);
    $history = StatusHistory::query()->create([
        'flow' => WorkflowNotificationTestModel::FLOW,
        'flow_id' => $model->id,
        'status' => 'done',
        'actor_id' => $actor->id,
        'actor_type' => $actor->type->value,
        'transitioned_at' => now(),
    ]);
    $context = new TransitionContext(actor: $actor);
    $payload = new TransitionPayload(
        flow: WorkflowNotificationTestModel::FLOW,
        flowModel: WorkflowNotificationTestModel::class,
        flowId: $model->id,
        fromStatus: 'open',
        toStatus: 'done',
        actorId: $actor->id,
        actorRole: null,
        actorDepartment: null,
        assignees: null,
        comment: null,
        commentTag: null,
        attachments: null,
        metadata: null,
        transitionedAt: now(),
    );

    return new TransitionCompleted(
        WorkflowNotificationTestModel::FLOW,
        $model,
        $transition,
        $history,
        $context,
        $payload,
    );
}

class WorkflowNotificationTestModel extends Model implements PresentsWorkflowNotifications
{
    public const string FLOW = 'notification_test';

    protected $table = 'workflow_notification_test_models';

    protected $guarded = [];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporter_id');
    }

    public function workflowNotificationTitle(): string
    {
        return $this->title;
    }

    public function workflowNotificationUrl(): ?string
    {
        return 'https://blb.test/workflow/'.$this->id;
    }
}
