<?php

namespace App\Modules\Core\Company\Models;

use App\Modules\Core\Company\Models\Concerns\BuildsCompanyAuditEntries;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyRelationship extends Model
{
    use BuildsCompanyAuditEntries;
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'company_relationships';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'related_company_id',
        'relationship_type_id',
        'effective_from',
        'effective_to',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the primary company in this relationship.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Get the related company in this relationship.
     */
    public function relatedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'related_company_id');
    }

    /**
     * Get the relationship type.
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(
            RelationshipType::class,
            'relationship_type_id'
        );
    }

    /**
     * Get external accesses granted through this relationship.
     */
    public function externalAccesses(): HasMany
    {
        return $this->hasMany(ExternalAccess::class, 'relationship_id');
    }

    /**
     * @return array{name: string, id: int}|null
     */
    public function getAuditSubject(): ?array
    {
        return $this->company_id !== null ? ['name' => 'company', 'id' => (int) $this->company_id] : null;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array<string, mixed>>
     */
    public function getAuditSubjectEntries(string $event, array $oldValues = [], array $newValues = []): array
    {
        $currentCompanyId = $this->company_id !== null ? (int) $this->company_id : null;
        $companyIds = [$this->related_company_id];

        if ($event === 'updated') {
            $companyIds[] = $this->getOriginal('company_id');
            $companyIds[] = $this->getOriginal('related_company_id');
        }

        return $this->companyAuditEntries($companyIds, $event, $oldValues, $newValues, $currentCompanyId);
    }

    /**
     * Check if the relationship is currently active based on temporal validity.
     */
    public function isActive(): bool
    {
        $now = now();

        $afterStart =
            is_null($this->effective_from) || $this->effective_from->lte($now);
        $beforeEnd =
            is_null($this->effective_to) || $this->effective_to->gte($now);

        return $afterStart && $beforeEnd;
    }

    /**
     * Check if the relationship has started.
     */
    public function hasStarted(): bool
    {
        return is_null($this->effective_from) ||
            $this->effective_from->lte(now());
    }

    /**
     * Check if the relationship has ended.
     */
    public function hasEnded(): bool
    {
        return ! is_null($this->effective_to) && $this->effective_to->lt(now());
    }

    /**
     * Check if the relationship is pending (future start date).
     */
    public function isPending(): bool
    {
        return ! is_null($this->effective_from) &&
            $this->effective_from->gt(now());
    }

    /**
     * End the relationship by setting effective_to to today.
     */
    public function end(): bool
    {
        $this->effective_to = now()->toDateString();

        return $this->save();
    }

    /**
     * Extend the relationship by setting a new end date.
     */
    public function extendTo(string $date): bool
    {
        $this->effective_to = $date;

        return $this->save();
    }

    /**
     * Remove the end date (make relationship indefinite).
     */
    public function makeIndefinite(): bool
    {
        $this->effective_to = null;

        return $this->save();
    }

    /**
     * Scope a query to only include active relationships.
     */
    public function scopeActive($query): void
    {
        $query
            ->where(function ($q): void {
                $q->whereNull('effective_from')->orWhere(
                    'effective_from',
                    '<=',
                    now()
                );
            })
            ->where(function ($q): void {
                $q->whereNull('effective_to')->orWhere(
                    'effective_to',
                    '>=',
                    now()
                );
            });
    }

    /**
     * Scope a query to only include relationships of a specific type code.
     *
     * @param  string  $typeCode  Relationship type code to filter by
     */
    public function scopeOfType($query, string $typeCode): void
    {
        $query->whereHas('type', function ($q) use ($typeCode): void {
            $q->where('code', $typeCode);
        });
    }

    /**
     * Scope a query to only include ended relationships.
     */
    public function scopeEnded($query): void
    {
        $query
            ->whereNotNull('effective_to')
            ->where('effective_to', '<', now());
    }

    /**
     * Scope a query to only include pending relationships.
     */
    public function scopePending($query): void
    {
        $query
            ->whereNotNull('effective_from')
            ->where('effective_from', '>', now());
    }

    /**
     * Scope a query to only include external relationships.
     */
    public function scopeExternal($query): void
    {
        $query->whereHas('type', function ($q): void {
            $q->where('is_external', true);
        });
    }

    /**
     * Scope a query to only include internal relationships.
     */
    public function scopeInternal($query): void
    {
        $query->whereHas('type', function ($q): void {
            $q->where('is_external', false);
        });
    }
}
