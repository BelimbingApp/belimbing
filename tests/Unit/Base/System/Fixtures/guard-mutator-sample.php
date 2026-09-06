<?php

declare(strict_types=1);

function guard_mutator_sample_alpha(): string
{
    return 'alpha';
}

function guard_mutator_sample_guard(): void
{
    // GUARD_LINE_UNIQUE
    throw new RuntimeException('guard should refuse');
}

function guard_mutator_sample_beta(): string
{
    return 'beta';
}

function guard_mutator_sample_duplicate_marker(): void
{
    // DUPLICATE_MARKER one
}

function guard_mutator_sample_duplicate_marker_two(): void
{
    // DUPLICATE_MARKER two
}
