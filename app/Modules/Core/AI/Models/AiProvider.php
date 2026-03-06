<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiProvider extends Model
{
    /**
     * @var string
     */
    protected $table = 'ai_providers';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'display_name',
        'base_url',
        'api_key',
        'is_active',
        'priority',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the company that owns this provider.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the employee who created this provider.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Get the models registered under this provider.
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiProviderModel::class, 'ai_provider_id');
    }

    /**
     * Scope to active providers only.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to providers with priority set (priority > 0), ordered by priority ascending.
     */
    public function scopePrioritized($query): void
    {
        $query->where('priority', '>', 0)->orderBy('priority');
    }

    /**
     * Scope to providers belonging to a specific company.
     *
     * @param  int  $companyId  Company ID
     */
    public function scopeForCompany($query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    /**
     * Get configured active providers for a company in runtime order.
     *
     * @return EloquentCollection<int, self>
     */
    public static function getConfiguredForCompany(int $companyId): EloquentCollection
    {
        return self::query()
            ->forCompany($companyId)
            ->active()
            ->orderBy('priority')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Assign the next available priority for this provider's company.
     *
     * Sets this provider to the lowest priority (highest number + 1).
     * If it already has a priority, does nothing.
     */
    public function assignNextPriority(): void
    {
        if ($this->priority > 0) {
            return;
        }

        $maxPriority = (int) self::query()
            ->where('company_id', $this->company_id)
            ->max('priority');

        $this->update(['priority' => $maxPriority + 1]);
    }

    /**
     * Set this provider as the top priority (1) for its company.
     *
     * Shifts all other prioritized providers down by 1.
     */
    public function setTopPriority(): void
    {
        if ($this->priority === 1) {
            return;
        }

        // Shift existing priorities down to make room at 1
        self::query()
            ->where('company_id', $this->company_id)
            ->where('priority', '>', 0)
            ->where('id', '!=', $this->id)
            ->increment('priority');

        $this->update(['priority' => 1]);
    }

    /**
     * Remove this provider from the priority ordering.
     */
    public function clearPriority(): void
    {
        $oldPriority = $this->priority;

        if ($oldPriority === 0) {
            return;
        }

        $this->update(['priority' => 0]);

        // Close the gap in priority sequence
        self::query()
            ->where('company_id', $this->company_id)
            ->where('priority', '>', $oldPriority)
            ->decrement('priority');
    }

    /**
     * Swap priority with another provider.
     *
     * @param  self  $other  The provider to swap priorities with
     */
    public function swapPriority(self $other): void
    {
        $thisPriority = $this->priority;
        $otherPriority = $other->priority;

        $this->update(['priority' => $otherPriority]);
        $other->update(['priority' => $thisPriority]);
    }
}
