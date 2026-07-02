<?php

namespace App\Base\Foundation\Livewire\Concerns;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Per-page selection for paginated Livewire lists.
 *
 * Compose onto a {@see Component} (with {@see WithPagination})
 * to expose a URL-persistent, option-clamped <code>$perPage</code> property and a
 * per-page option list for the {@see x_ui_pagination} Blade component.
 *
 * The default page size and option set are overridable per class via the
 * {@see defaultPerPage()} hook and {@see PER_PAGE_OPTIONS} constant. Override
 * {@see perPageOptions()} to return an empty array to hide the selector.
 */
trait SelectsPerPage
{
    /** Maximum page size when no explicit option list is declared. */
    protected const int PER_PAGE_MAX = 200;

    /**
     * Default per-page option list. Override per class to change the selector.
     *
     * @var list<int>
     */
    protected const array PER_PAGE_OPTIONS = [10, 25, 50, 100];

    #[Url]
    public int $perPage = 25;

    /**
     * Per-class default page size applied when the URL does not supply
     * <code>?perPage=</code>. Override instead of writing a <code>mount()</code>
     * guard — Livewire hydrates <code>#[Url]</code> properties <em>before</em>
     * the component's <code>mount()</code>, so an unconditional assignment there
     * would clobber a shared/bookmarked URL.
     */
    protected function defaultPerPage(): int
    {
        return 25;
    }

    /**
     * Whether the current request carries a per-page override. Seamed out so
     * the trait's {@see mountSelectsPerPage} logic stays testable without a
     * live HTTP request.
     */
    protected function hasPerPageInUrl(): bool
    {
        return request()->has('perPage');
    }

    /**
     * Livewire trait mount hook (<code>mount{Trait}</code>): runs once on
     * initial render, <em>after</em> <code>#[Url]</code> hydration, and never
     * on subsequent AJAX round-trips. Applies the per-class default only when
     * the URL did not supply <code>perPage</code>, then normalizes/clamps so
     * the bound selector and the actual query agree.
     */
    public function mountSelectsPerPage(): void
    {
        if (! $this->hasPerPageInUrl()) {
            $this->perPage = $this->defaultPerPage();
        }

        $this->perPage = $this->clampedPerPage();
    }

    /**
     * Options rendered by the per-page selector. Return an empty list to hide it.
     *
     * @return list<int>
     */
    public function perPageOptions(): array
    {
        return static::PER_PAGE_OPTIONS;
    }

    /**
     * Livewire hook: clamp the incoming value and reset to the first page so an
     * out-of-range page (or a stale <code>?perPage=</code> query param) cannot
     * land the user beyond the result set.
     */
    public function updatedPerPage(mixed $value): void
    {
        $this->perPage = $this->clampedPerPage(is_numeric($value) ? (int) $value : null);

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * Clamp a per-page value to the option list (when declared) or the sane max,
     * falling back to the first option or the default of 25.
     *
     * Write-through: the stored {@see $perPage} is normalized to the clamped
     * value on read so the bound selector and the actual query always agree —
     * a stale or hand-crafted <code>?perPage=9999</code> URL self-heals instead
     * of leaving the <code>&lt;select&gt;</code> showing a value that does not
     * match the rendered page size.
     */
    protected function clampedPerPage(?int $value = null): int
    {
        $value ??= $this->perPage;
        $options = $this->perPageOptions();

        if ($options !== []) {
            if (in_array($value, $options, true)) {
                $clamped = $value;
            } else {
                $clamped = null;
                foreach ($options as $option) {
                    if ($option >= $value) {
                        $clamped = $option;
                        break;
                    }
                }
                // Value exceeds every declared option: cap at the largest.
                $clamped ??= $options[array_key_last($options)];
            }

            return $this->perPage = $clamped;
        }

        $max = static::PER_PAGE_MAX;

        return $this->perPage = max(1, min($value, $max));
    }
}
