<?php
namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * AI Run — universal execution envelope for AI work.
 *
 * Each row represents one submitted unit of AI work: interactive chat,
 * background tasks, orchestration sessions, or utility LLM calls. It owns the
 * lifecycle state, wire-log key, ordered event stream, and aggregate usage.
 * Never stores prompts, response bodies, or secrets.
 *
 * @property string $id
 * @property int $employee_id
 * @property string|null $session_id
 * @property int|null $acting_for_user_id
 * @property string|null $dispatch_id
 * @property string $source
 * @property string $execution_mode
 * @property AiRunStatus $status
 * @property RunPhase|null $current_phase
 * @property string|null $current_label
 * @property int|null $last_event_seq
 * @property Carbon|null $cancel_requested_at
 * @property array<string, mixed>|null $runtime_meta
 * @property string|null $provider_name
 * @property string|null $model
 * @property int|null $timeout_seconds
 * @property int|null $latency_ms
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property int|null $cached_input_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $total_tokens
 * @property int|null $cost_input_cents
 * @property int|null $cost_cached_input_cents
 * @property int|null $cost_output_cents
 * @property int|null $cost_total_cents
 * @property string|null $pricing_version
 * @property int $call_count
 * @property list<array{provider: string, model: string, error: string, error_type: string, latency_ms: int}>|null $retry_attempts
 * @property list<array{tool: string, result_length: int|null}>|null $tool_actions
 * @property string|null $error_type
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $actingForUser
 * @property-read OperationDispatch|null $dispatch
 * @property-read Collection<int, AiRunEvent> $events
 * @property-read Collection<int, AiRunCall> $calls
 */
class AiRun extends Model
{
    use HasUlids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_runs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'employee_id',
        'session_id',
        'acting_for_user_id',
        'dispatch_id',
        'source',
        'execution_mode',
        'status',
        'current_phase',
        'current_label',
        'last_event_seq',
        'cancel_requested_at',
        'runtime_meta',
        'provider_name',
        'model',
        'timeout_seconds',
        'latency_ms',
        'prompt_tokens',
        'completion_tokens',
        'cached_input_tokens',
        'reasoning_tokens',
        'total_tokens',
        'cost_input_cents',
        'cost_cached_input_cents',
        'cost_output_cents',
        'cost_total_cents',
        'pricing_version',
        'call_count',
        'retry_attempts',
        'tool_actions',
        'error_type',
        'error_message',
        'meta',
        'started_at',
        'finished_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AiRunStatus::class,
            'current_phase' => RunPhase::class,
            'last_event_seq' => 'integer',
            'cancel_requested_at' => 'datetime',
            'runtime_meta' => 'json',
            'retry_attempts' => 'json',
            'tool_actions' => 'json',
            'meta' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the agent (employee) that executed this run.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the user on whose behalf this run was executed.
     */
    public function actingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    /**
     * Get the linked operation dispatch (for background/async runs).
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(OperationDispatch::class, 'dispatch_id');
    }

    /**
     * Per-LLM-call usage rows for this run.
     */
    public function calls(): HasMany
    {
        return $this->hasMany(AiRunCall::class, 'run_id')->orderBy('attempt_index');
    }

    /**
     * Ordered event stream for this run.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AiRunEvent::class, 'run_id')->orderBy('seq');
    }

    /**
     * Events after a given sequence number for SSE resume.
     *
     * @return HasMany<AiRunEvent, $this>
     */
    public function eventsAfter(int $afterSeq): HasMany
    {
        return $this->events()->where('seq', '>', $afterSeq);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    public function requestCancel(string $reason = 'User pressed stop'): void
    {
        $this->cancel_requested_at = now();
        $this->runtime_meta = array_merge($this->runtime_meta ?? [], [
            'cancel_reason' => $reason,
        ]);
        $this->save();
    }

    public function isBusy(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Allocate the next event sequence number.
     */
    public function nextSeq(): int
    {
        if ($this->last_event_seq === null) {
            $this->forceFill(['last_event_seq' => 0])->save();
        }

        $this->increment('last_event_seq');
        $this->refresh();

        return (int) $this->last_event_seq;
    }

    public function transitionTo(AiRunStatus $newStatus): void
    {
        if ($this->status === $newStatus) {
            return;
        }

        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition run from {$this->status->value} to {$newStatus->value}"
            );
        }

        $this->status = $newStatus;

        if ($newStatus === AiRunStatus::Running && $this->started_at === null) {
            $this->started_at = now();
        }

        if ($newStatus->isTerminal()) {
            $finishedAt = now();
            $this->finished_at = $finishedAt;

            if ($this->latency_ms === null && $this->started_at !== null) {
                $this->latency_ms = max(0, (int) $this->started_at->diffInMilliseconds($finishedAt));
            }
        }

        $this->save();
    }

    public function updatePhase(RunPhase $phase, ?string $label = null): void
    {
        $this->current_phase = $phase;
        $this->current_label = $label;
        $this->save();
    }

    public function finalize(AiRunStatus $terminalStatus, ?array $payload = null): void
    {
        $eventType = match ($terminalStatus) {
            AiRunStatus::Succeeded => RunEventType::RunCompleted,
            AiRunStatus::Failed, AiRunStatus::TimedOut => RunEventType::RunFailed,
            AiRunStatus::Cancelled => RunEventType::RunCancelled,
            default => throw new \InvalidArgumentException(
                "Cannot finalize with non-terminal status: {$terminalStatus->value}"
            ),
        };

        $seq = $this->nextSeq();

        AiRunEvent::query()->create([
            'run_id' => $this->id,
            'seq' => $seq,
            'event_type' => $eventType->value,
            'payload' => $payload,
        ]);

        $this->transitionTo($terminalStatus);
    }
}
