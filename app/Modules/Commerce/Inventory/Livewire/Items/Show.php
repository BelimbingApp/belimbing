<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire\Items;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\SavesValidatedFields;
use App\Modules\Commerce\Inventory\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Show extends Component
{
    use SavesValidatedFields;

    public Item $item;

    public function mount(Item $item): void
    {
        if ($item->company_id !== Auth::user()?->company_id) {
            abort(404);
        }

        $this->item = $item;
    }

    public function saveField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        $this->saveValidatedField(
            $this->item,
            $field,
            $value,
            $this->fieldRules(),
            function ($model, string $field, mixed $validatedValue): void {
                if ($field === 'currency_code') {
                    $model->currency_code = strtoupper($validatedValue);
                }

                if ($field === 'description' && trim((string) $validatedValue) === '') {
                    $model->description = null;
                }
            },
        );

        $this->item->refresh();
    }

    public function saveMoneyField(string $field, mixed $value): void
    {
        $this->authorizeUpdate();

        if (! in_array($field, ['unit_cost_amount', 'target_price_amount'], true)) {
            return;
        }

        $validated = validator(
            [$field => $value],
            [$field => ['nullable', 'regex:/^\d{1,7}(\.\d{1,2})?$/']],
        )->validate();

        $this->item->update([$field => $this->parseMoneyAmount($validated[$field] ?? null)]);
        $this->item->refresh();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(Item::statuses())],
            'currency_code' => ['required', 'string', 'size:3'],
        ];
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            Item::STATUS_DRAFT => 'default',
            Item::STATUS_READY => 'info',
            Item::STATUS_LISTED => 'accent',
            Item::STATUS_SOLD => 'success',
            Item::STATUS_ARCHIVED => 'default',
            default => 'default',
        };
    }

    public function formatMoney(?int $amount, string $currencyCode): string
    {
        if ($amount === null) {
            return '—';
        }

        return $currencyCode.' '.number_format($amount / 100, 2);
    }

    public function canEdit(): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), 'inventory.item.update')
            ->allowed;
    }

    public function render(): View
    {
        return view('livewire.commerce.inventory.items.show', [
            'statuses' => Item::statuses(),
        ]);
    }

    public function formatMoneyInput(?int $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        return number_format($amount / 100, 2, '.', '');
    }

    private function authorizeUpdate(): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            'inventory.item.update',
        );
    }

    private function parseMoneyAmount(mixed $amount): ?int
    {
        if ($amount === null || trim($amount) === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }
}
