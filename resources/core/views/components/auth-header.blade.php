@props([
    'title' => null,
    'description' => null,
])

<div class="flex w-full flex-col text-center">
    @if (filled($title))
        <h1 class="text-3xl font-bold text-neutral-900 dark:text-neutral-50">{{ $title }}</h1>
    @endif

    @if (filled($description))
        <p class="{{ filled($title) ? 'mt-2' : '' }} text-neutral-600 dark:text-neutral-300">{{ $description }}</p>
    @endif
</div>
