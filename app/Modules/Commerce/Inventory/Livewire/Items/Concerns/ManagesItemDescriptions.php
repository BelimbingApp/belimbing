<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items\Concerns;

use App\Modules\Commerce\Catalog\Models\Description as CatalogDescription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait ManagesItemDescriptions
{
    public string $descriptionTitle = '';

    public string $descriptionBody = '';

    public function addDescription(): void
    {
        $this->authorizeUpdate();

        $validated = $this->validate([
            'descriptionTitle' => ['required', 'string', 'max:255'],
            'descriptionBody' => ['required', 'string', 'max:10000'],
        ]);

        $nextVersion = ((int) $this->item->descriptions()->max('version')) + 1;

        CatalogDescription::query()->create([
            'item_id' => $this->item->id,
            'created_by_user_id' => Auth::id(),
            'version' => $nextVersion,
            'title' => $validated['descriptionTitle'],
            'body' => $validated['descriptionBody'],
            'source' => CatalogDescription::SOURCE_MANUAL,
        ]);

        $this->reset('descriptionTitle', 'descriptionBody');
        $this->item->load('descriptions.createdByUser');
    }

    public function acceptDescription(int $descriptionId): void
    {
        $this->authorizeUpdate();

        $description = $this->item->descriptions->firstWhere('id', $descriptionId);

        if (! $description instanceof CatalogDescription) {
            return;
        }

        DB::transaction(function () use ($description): void {
            $this->item->descriptions()->update(['is_accepted' => false]);
            $description->update(['is_accepted' => true]);
        });

        $this->item->load('descriptions.createdByUser');
    }

    public function saveDescriptionField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        $parts = explode('.', $field);
        if (count($parts) !== 3 || $parts[0] !== 'descriptions') {
            return;
        }

        $descriptionId = (int) $parts[1];
        $column = $parts[2];

        if (! in_array($column, ['title', 'body'], true)) {
            return;
        }

        $description = $this->item->descriptions->firstWhere('id', $descriptionId);
        if (! $description instanceof CatalogDescription) {
            return;
        }

        $key = 'descriptions.'.$descriptionId.'.'.$column;

        $rules = match ($column) {
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            default => ['nullable'],
        };

        $validated = validator([$key => $value], [$key => $rules])->validate();
        $validatedValue = $validated[$key] ?? null;

        $description->update([$column => $validatedValue]);

        $this->item->load('descriptions.createdByUser');
    }

    public function deleteDescription(int $descriptionId): void
    {
        $this->authorizeUpdate();

        $description = $this->item->descriptions->firstWhere('id', $descriptionId);
        if (! $description instanceof CatalogDescription) {
            return;
        }

        $description->delete();
        $this->item->load('descriptions.createdByUser');
    }
}
