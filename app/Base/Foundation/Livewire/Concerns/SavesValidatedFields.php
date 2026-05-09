<?php
namespace App\Base\Foundation\Livewire\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

trait SavesValidatedFields
{
    /**
     * Validate a single field and persist it onto the given model.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @param  Closure(Model, string, mixed): void|null  $beforeSave
     */
    protected function saveValidatedField(
        Model $model,
        string $field,
        mixed $value,
        array $rules,
        ?Closure $beforeSave = null,
    ): bool {
        if (! isset($rules[$field])) {
            return false;
        }

        $validated = validator([$field => $value], [$field => $rules[$field]])->validate();
        $validatedValue = $validated[$field];

        $model->$field = $validatedValue;

        if ($beforeSave !== null) {
            $beforeSave($model, $field, $validatedValue);
        }

        $model->save();

        return true;
    }
}
