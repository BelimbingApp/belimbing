<?php

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Process registry — lightweight catalog of all configured workflows.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $module
 * @property string|null $description
 * @property string|null $model_class
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StatusConfig> $statusConfigs
 * @property-read Collection<int, StatusTransition> $transitions
 * @property-read Collection<int, KanbanColumn> $kanbanColumns
 */
class Workflow extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_workflow';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'label',
        'module',
        'description',
        'model_class',
        'settings',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'json',
        'is_active' => 'boolean',
    ];

    public function statusConfigs(): HasMany
    {
        return $this->hasMany(StatusConfig::class, 'flow', 'code')
            ->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'flow', 'code');
    }

    public function kanbanColumns(): HasMany
    {
        return $this->hasMany(KanbanColumn::class, 'flow', 'code')
            ->orderBy('position');
    }

    /** @return array{name: string, id: int}|null */
    public function getAuditSubject(): ?array
    {
        // Not `exists`: performDeleteOnModel() clears it before firing
        // `deleted`, and the global audit MutationListener resolves the subject
        // on that event -- so guarding on exists writes a null subject_name and
        // subject_id for every delete. The key survives the delete, which is
        // exactly what the audit row needs. Guarding on getKey() also keeps
        // Larastan happy, since `@property int $id` made `$this->id === null`
        // look dead while it was still reachable for an unsaved model.
        if ($this->getKey() === null) {
            return null;
        }

        return ['name' => 'workflow', 'id' => $this->id];
    }

    /**
     * Scope to active workflows only.
     */
    #[ScopeAttribute]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to workflows belonging to a specific module.
     */
    #[ScopeAttribute]
    protected function forModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
