<?php

use App\Base\Foundation\Livewire\Concerns\SelectsPerPage;

function perPageHarness(array $options = [10, 25, 50, 100], int $default = 25): object
{
    return new class($options, $default)
    {
        use SelectsPerPage {
            SelectsPerPage::updatedPerPage as public;
            SelectsPerPage::clampedPerPage as public;
        }

        public bool $pageReset = false;

        public function __construct(array $options, int $default)
        {
            $this->perPage = $default;
            $this->options = $options;
        }

        /** @var list<int> */
        public array $options;

        public function perPageOptions(): array
        {
            return $this->options;
        }

        public function resetPage(): void
        {
            $this->pageReset = true;
        }
    };
}

it('clamps an in-range value to the matching option unchanged', function (): void {
    $harness = perPageHarness();

    $harness->updatedPerPage(50);

    expect($harness->perPage)->toBe(50)
        ->and($harness->pageReset)->toBeTrue();
});

it('rounds up to the next option when between options', function (): void {
    $harness = perPageHarness();

    $harness->updatedPerPage(30);

    expect($harness->perPage)->toBe(50);
});

it('snaps back to the first option when below the smallest option', function (): void {
    $harness = perPageHarness();

    $harness->updatedPerPage(1);

    expect($harness->perPage)->toBe(10);
});

it('caps an over-max value at the largest option', function (): void {
    $harness = perPageHarness();

    $harness->updatedPerPage(9999);

    expect($harness->perPage)->toBe(100);
});

it('clamps to a sane max and never resets the page when no option list is declared', function (): void {
    // clampedPerPage() is a pure read — it must not trigger resetPage().
    $harness = perPageHarness(options: [], default: 25);

    expect($harness->clampedPerPage(300))->toBe(200)
        ->and($harness->clampedPerPage(0))->toBe(1)
        ->and($harness->pageReset)->toBeFalse();
});

it('coerces non-numeric input defensively by keeping the current page size', function (): void {
    $harness = perPageHarness();

    $harness->updatedPerPage('not-a-number');

    expect($harness->perPage)->toBe(25)
        ->and($harness->pageReset)->toBeTrue();
});

function perPageMountHarness(bool $urlHasPerPage, int $defaultPerPage = 20): object
{
    return new class($urlHasPerPage, $defaultPerPage)
    {
        use SelectsPerPage {
            SelectsPerPage::mountSelectsPerPage as public;
            SelectsPerPage::clampedPerPage as public;
        }

        public bool $pageReset = false;

        public function __construct(public bool $urlHasPerPage, public int $defaultPerPageValue)
        {
        }

        protected function defaultPerPage(): int
        {
            return $this->defaultPerPageValue;
        }

        protected function hasPerPageInUrl(): bool
        {
            return $this->urlHasPerPage;
        }

        public function perPageOptions(): array
        {
            return [10, 20, 50, 100];
        }

        public function resetPage(): void
        {
            $this->pageReset = true;
        }
    };
}

it('applies the per-class default page size when the URL does not supply perPage', function (): void {
    $harness = perPageMountHarness(urlHasPerPage: false, defaultPerPage: 20);

    $harness->mountSelectsPerPage();

    expect($harness->perPage)->toBe(20);
});

it('preserves a URL-supplied perPage over the per-class default', function (): void {
    $harness = perPageMountHarness(urlHasPerPage: true, defaultPerPage: 20);
    $harness->perPage = 50; // simulate #[Url] hydration from ?perPage=50

    $harness->mountSelectsPerPage();

    expect($harness->perPage)->toBe(50);
});

it('clamps an out-of-range URL perPage during the mount hook', function (): void {
    $harness = perPageMountHarness(urlHasPerPage: true, defaultPerPage: 20);
    $harness->perPage = 9999; // stale/hand-crafted ?perPage=9999

    $harness->mountSelectsPerPage();

    expect($harness->perPage)->toBe(100);
});
