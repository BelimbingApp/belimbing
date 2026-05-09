<?php
namespace App\Modules\Core\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'ai_provider_models';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'ai_provider_id',
        'model_id',
        'is_active',
        'is_default',
        'cost_override',
        'execution_controls',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'cost_override' => 'array',
            'execution_controls' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get cost for a given dimension, preferring admin override over catalog data.
     *
     * @param  string  $key  One of: input, output, cache_read, cache_write
     * @param  array<string, mixed>|null  $catalogCost  Cost data from models.dev catalog
     */
    public function getCost(string $key, ?array $catalogCost = null): ?string
    {
        $override = $this->cost_override;

        if (is_array($override) && isset($override[$key]) && $override[$key] !== null && $override[$key] !== '') {
            return (string) $override[$key];
        }

        if (is_array($catalogCost) && isset($catalogCost[$key]) && $catalogCost[$key] !== null) {
            return (string) $catalogCost[$key];
        }

        return null;
    }

    /**
     * Get the provider this model belongs to.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Scope to active models only.
     */
    public function scopeActive($query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to the default model.
     */
    public function scopeDefault($query): void
    {
        $query->where('is_default', true);
    }

    /**
     * Set this model as the default for its provider, unsetting any previous default.
     */
    public function setAsDefault(): void
    {
        self::query()
            ->where('ai_provider_id', $this->ai_provider_id)
            ->where('is_default', true)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Unset this model as default.
     */
    public function unsetDefault(): void
    {
        $this->update(['is_default' => false]);
    }
}
