<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit\Listeners;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Support\Str as BlbStr;
use Illuminate\Database\Eloquent\Model;

/**
 * Global Eloquent event listener that captures create/update/delete
 * mutations on ALL models. Models opt out via config or properties
 * instead of opting in with a trait.
 */
class MutationListener
{
    /**
     * Global kill switch for auditing.
     */
    private static bool $disabled = false;

    /**
     * Execute a callback with mutation auditing disabled.
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = false;
        }
    }

    /**
     * Handle a wildcard Eloquent event.
     *
     * @param  string  $eventName  e.g. "eloquent.created: App\Models\User"
     * @param  array<int, mixed>  $data
     */
    public function handle(string $eventName, array $data): void
    {
        if (self::$disabled) {
            return;
        }

        if ($this->isSeeding()) {
            return;
        }

        $model = $data[0] ?? null;
        if (! $model instanceof Model) {
            return;
        }

        $event = $this->resolveEvent($eventName);
        if ($event === null) {
            return;
        }

        $excludedModels = config('audit.exclude_models', []);
        if (in_array($model::class, $excludedModels, true)) {
            return;
        }

        $changes = $this->resolveChanges($model, $event);
        if ($changes === null) {
            return;
        }

        [$oldValues, $newValues] = $changes;

        $context = app(RequestContext::class);
        $now = now();

        app(AuditBuffer::class)->bufferMutation([
            'company_id' => $context->companyId,
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'actor_role' => $context->actorRole,
            'ip_address' => $context->ipAddress,
            'url' => $context->url,
            'user_agent' => $context->userAgent,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => json_encode($oldValues !== [] ? $oldValues : null),
            'new_values' => json_encode($newValues !== [] ? $newValues : null),
            'correlation_id' => $context->correlationId,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * Extract the event type (created/updated/deleted) from the wildcard event name.
     */
    private function resolveEvent(string $eventName): ?string
    {
        if (str_starts_with($eventName, 'eloquent.created:')) {
            return 'created';
        }
        if (str_starts_with($eventName, 'eloquent.updated:')) {
            return 'updated';
        }
        if (str_starts_with($eventName, 'eloquent.deleted:')) {
            return 'deleted';
        }

        return null;
    }

    /**
     * Resolve old and new values for the mutation, applying field strategies.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null Null if no meaningful changes.
     */
    private function resolveChanges(Model $model, string $event): ?array
    {
        $excludedFields = $this->resolveExcludedFields($model);
        $redactedFields = $this->resolveRedactedFields($model);
        $truncateFields = $this->resolveTruncateFields($model);
        $encryptedFields = $this->resolveEncryptedFields($model);
        $truncateDefault = (int) config('audit.truncate_default', 2000);

        $args = [$excludedFields, $redactedFields, $encryptedFields, $truncateFields, $truncateDefault];

        if ($event === 'created') {
            return [[], $this->collectFieldValues($model->getAttributes(), ...$args)];
        }

        if ($event === 'deleted') {
            return [$this->collectFieldValues($model->getAttributes(), ...$args), []];
        }

        if ($event === 'updated') {
            $dirty = $model->getDirty();
            $keys = array_keys($dirty);
            $originals = array_combine($keys, array_map(fn ($f) => $model->getOriginal($f), $keys));
            $newValues = $this->collectFieldValues($dirty, ...$args);

            if ($newValues === []) {
                return null;
            }

            return [$this->collectFieldValues($originals, ...$args), $newValues];
        }

        return [[], []];
    }

    /**
     * Map an attribute array through field exclusion and strategy application.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $excludedFields
     * @param  array<int, string>  $redactedFields
     * @param  array<int, string>  $encryptedFields
     * @param  array<string, int>  $truncateFields
     * @return array<string, mixed>
     */
    private function collectFieldValues(
        array $attributes,
        array $excludedFields,
        array $redactedFields,
        array $encryptedFields,
        array $truncateFields,
        int $truncateDefault,
    ): array {
        $result = [];

        foreach ($attributes as $field => $value) {
            if (in_array($field, $excludedFields, true)) {
                continue;
            }

            $result[$field] = $this->applyFieldStrategy(
                $field, $value, $redactedFields, $encryptedFields, $truncateFields, $truncateDefault
            );
        }

        return $result;
    }

    /**
     * Apply the appropriate strategy (redact, truncate, full) to a field value.
     *
     * @param  array<int, string>  $redactedFields
     * @param  array<int, string>  $encryptedFields
     * @param  array<string, int>  $truncateFields
     */
    private function applyFieldStrategy(
        string $field,
        mixed $value,
        array $redactedFields,
        array $encryptedFields,
        array $truncateFields,
        int $truncateDefault,
    ): mixed {
        if (in_array($field, $redactedFields, true) || in_array($field, $encryptedFields, true)) {
            return '[redacted]';
        }

        if ($value === null) {
            return null;
        }

        if (isset($truncateFields[$field]) && is_string($value)) {
            return BlbStr::truncateWithCount($value, $truncateFields[$field]);
        }

        if (is_string($value)) {
            return BlbStr::truncateWithCount($value, $truncateDefault);
        }

        return $value;
    }

    /**
     * Merge global and model-level excluded fields.
     *
     * @return array<int, string>
     */
    private function resolveExcludedFields(Model $model): array
    {
        $global = config('audit.exclude_fields', []);
        $modelLevel = $this->readModelProperty($model, 'auditExclude', []);

        return array_merge($global, $modelLevel);
    }

    /**
     * Merge global and model-level redacted fields.
     *
     * @return array<int, string>
     */
    private function resolveRedactedFields(Model $model): array
    {
        $global = config('audit.redact', []);
        $modelLevel = $this->readModelProperty($model, 'auditRedact', []);

        return array_merge($global, $modelLevel);
    }

    /**
     * Resolve model-level truncation overrides.
     *
     * @return array<string, int>
     */
    private function resolveTruncateFields(Model $model): array
    {
        return $this->readModelProperty($model, 'auditTruncate', []);
    }

    /**
     * Read a property from a model regardless of visibility.
     *
     * Models define audit properties as protected arrays. Since the
     * listener is external, reflection is needed to read them.
     */
    private function readModelProperty(Model $model, string $property, mixed $default): mixed
    {
        if (! property_exists($model, $property)) {
            return $default;
        }

        $reflection = new \ReflectionProperty($model, $property); // NOSONAR — reflection used to read model property metadata; $property comes from internal code, not user input

        return $reflection->getValue($model) ?? $default;
    }

    /**
     * Detect encrypted cast fields on the model.
     *
     * @return array<int, string>
     */
    private function resolveEncryptedFields(Model $model): array
    {
        $encrypted = [];

        foreach ($model->getCasts() as $field => $cast) {
            if (str_starts_with($cast, 'encrypted')) {
                $encrypted[] = $field;
            }
        }

        return $encrypted;
    }

    /**
     * Check if the application is currently running a seeder or migration.
     */
    private function isSeeding(): bool
    {
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        foreach ($argv as $arg) {
            if (str_contains($arg, 'seed') || str_contains($arg, 'migrate')) {
                return true;
            }
        }

        return false;
    }
}
