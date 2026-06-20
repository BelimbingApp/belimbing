<?php

namespace App\Base\Foundation\Livewire\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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

        try {
            $validated = validator([$field => $value], [$field => $rules[$field]])->validate();
        } catch (ValidationException $exception) {
            session()->flash('error', __('Could not save changes. Review the highlighted field.'));

            throw $exception;
        }

        $validatedValue = $validated[$field];

        $model->$field = $validatedValue;

        if ($beforeSave !== null) {
            $beforeSave($model, $field, $validatedValue);
        }

        $model->save();

        session()->flash('success', __('Saved.'));

        return true;
    }
}
