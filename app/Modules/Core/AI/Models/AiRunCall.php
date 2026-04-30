<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI Run Call — one row per LLM call.
 *
 * A run can be a multi-call tool loop, so each iteration's `usage` lands
 * here rather than overwriting a single slot on `ai_runs`. Run-level
 * aggregates are recomputed from these rows by the recorder.
 *
 * @property int $id
 * @property string $run_id
 * @property int $attempt_index
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $finish_reason
 * @property string|null $native_finish_reason
 * @property int|null $latency_ms
 * @property int|null $prompt_tokens
 * @property int|null $cached_input_tokens
 * @property int|null $completion_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $total_tokens
 * @property array<string, mixed>|null $raw_usage
 * @property string|null $pricing_source
 * @property string|null $pricing_version
 * @property int|null $cost_input_cents
 * @property int|null $cost_cached_input_cents
 * @property int|null $cost_output_cents
 * @property int|null $cost_total_cents
 * @property int|null $rate_limit_remaining_requests
 * @property int|null $rate_limit_remaining_tokens
 * @property Carbon|null $rate_limit_reset_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiRun $run
 */
class AiRunCall extends Model
{
    protected $table = 'ai_run_calls';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'run_id',
        'attempt_index',
        'provider',
        'model',
        'finish_reason',
        'native_finish_reason',
        'latency_ms',
        'prompt_tokens',
        'cached_input_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'total_tokens',
        'raw_usage',
        'pricing_source',
        'pricing_version',
        'cost_input_cents',
        'cost_cached_input_cents',
        'cost_output_cents',
        'cost_total_cents',
        'rate_limit_remaining_requests',
        'rate_limit_remaining_tokens',
        'rate_limit_reset_at',
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
            'raw_usage' => 'json',
            'rate_limit_reset_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the parent run.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'run_id');
    }
}
