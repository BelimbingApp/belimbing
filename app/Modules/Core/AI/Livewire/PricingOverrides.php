<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Models\AiPricingOverride;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class PricingOverrides extends Component implements ProvidesLaraPageContext
{
    use WithPagination;

    public string $search = '';

    public ?int $editingOverrideId = null;

    public string $provider = '';

    public string $model = '';

    public string $inputUsdPerMillionTokens = '';

    public string $cachedInputUsdPerMillionTokens = '';

    public string $outputUsdPerMillionTokens = '';

    public string $reason = '';

    public function pageContext(): PageContext
    {
        return new PageContext(
            route: 'admin.ai.pricing-overrides',
            url: route('admin.ai.pricing-overrides'),
            title: 'AI Pricing Overrides',
            module: 'AI',
            resourceType: 'pricing-override',
            visibleActions: ['Create pricing override', 'Edit pricing override', 'Delete pricing override'],
        );
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function editOverride(int $overrideId): void
    {
        $override = AiPricingOverride::query()->findOrFail($overrideId);

        $this->editingOverrideId = $override->id;
        $this->provider = $override->provider ?? '';
        $this->model = $override->model;
        $this->inputUsdPerMillionTokens = $override->input_usd_per_million_tokens;
        $this->cachedInputUsdPerMillionTokens = $override->cached_input_usd_per_million_tokens ?? '';
        $this->outputUsdPerMillionTokens = $override->output_usd_per_million_tokens;
        $this->reason = $override->reason ?? '';
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function saveOverride(): void
    {
        $validated = $this->validate($this->rules());
        $provider = $this->blankToNull($validated['provider']);
        $model = trim($validated['model']);

        if ($this->duplicateExists($provider, $model)) {
            $this->addError('model', __('A pricing override already exists for this provider/model.'));

            return;
        }

        $attributes = [
            'provider' => $provider,
            'model' => $model,
            'input_usd_per_million_tokens' => $this->normalizeDecimal($validated['inputUsdPerMillionTokens']),
            'cached_input_usd_per_million_tokens' => $this->blankToNull($validated['cachedInputUsdPerMillionTokens']) !== null
                ? $this->normalizeDecimal($validated['cachedInputUsdPerMillionTokens'])
                : null,
            'output_usd_per_million_tokens' => $this->normalizeDecimal($validated['outputUsdPerMillionTokens']),
            'reason' => $this->blankToNull($validated['reason']),
        ];

        if ($this->editingOverrideId !== null) {
            AiPricingOverride::query()->findOrFail($this->editingOverrideId)->update($attributes);
            $event = 'pricing-override-updated';
        } else {
            AiPricingOverride::query()->create([
                ...$attributes,
                'created_by' => Auth::id(),
            ]);
            $event = 'pricing-override-created';
        }

        $this->resetForm();
        $this->dispatch($event);
    }

    public function deleteOverride(int $overrideId): void
    {
        AiPricingOverride::query()->whereKey($overrideId)->delete();

        if ($this->editingOverrideId === $overrideId) {
            $this->resetForm();
        }

        $this->dispatch('pricing-override-deleted');
    }

    public function render(): View
    {
        $overrides = AiPricingOverride::query()
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('provider', 'like', $search)
                        ->orWhere('model', 'like', $search)
                        ->orWhere('reason', 'like', $search);
                });
            })
            ->orderByRaw('provider is null')
            ->orderBy('provider')
            ->orderBy('model')
            ->paginate(25);

        return view('livewire.admin.ai.pricing-overrides', [
            'overrides' => $overrides,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $decimalRule = 'regex:/^\d+(\.\d{1,12})?$/';

        return [
            'provider' => ['nullable', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:255'],
            'inputUsdPerMillionTokens' => ['required', $decimalRule],
            'cachedInputUsdPerMillionTokens' => ['nullable', $decimalRule],
            'outputUsdPerMillionTokens' => ['required', $decimalRule],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function duplicateExists(?string $provider, string $model): bool
    {
        return AiPricingOverride::query()
            ->where('model', $model)
            ->when(
                $provider === null,
                fn (Builder $query): Builder => $query->whereNull('provider'),
                fn (Builder $query): Builder => $query->where('provider', $provider),
            )
            ->when(
                $this->editingOverrideId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($this->editingOverrideId),
            )
            ->exists();
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingOverrideId',
            'provider',
            'model',
            'inputUsdPerMillionTokens',
            'cachedInputUsdPerMillionTokens',
            'outputUsdPerMillionTokens',
            'reason',
        ]);
        $this->resetValidation();
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDecimal(string $value): string
    {
        $trimmed = trim($value);
        [$integer, $fraction] = array_pad(explode('.', $trimmed, 2), 2, '');
        $integer = ltrim($integer, '0');

        if ($integer === '') {
            $integer = '0';
        }

        return $integer.'.'.str_pad(substr($fraction, 0, 12), 12, '0');
    }
}
