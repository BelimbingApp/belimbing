<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;

it('resolves the same scoped instance within an execution', function (): void {
    $context = app(TenantContext::class);

    expect(app(TenantContext::class))->toBe($context);
    expect($context->currentTenantId())->toBeNull();
    expect($context->hasTenant())->toBeFalse();
});

it('sets, reads, and clears the current tenant', function (): void {
    $context = app(TenantContext::class);

    $context->set(7);

    expect($context->currentTenantId())->toBe(7);
    expect($context->hasTenant())->toBeTrue();
    expect($context->requireTenantId())->toBe(7);

    $context->clear();

    expect($context->currentTenantId())->toBeNull();
});

it('fails closed when a tenant is required but missing', function (): void {
    app(TenantContext::class)->requireTenantId();
})->throws(TenantContextMissingException::class);

it('restores the previous context after a scoped run', function (): void {
    $context = app(TenantContext::class);
    $context->set(1);

    $observed = $context->runForTenant(9, fn (): ?int => $context->currentTenantId());

    expect($observed)->toBe(9);
    expect($context->currentTenantId())->toBe(1);
});

it('restores the previous context when the scoped run throws', function (): void {
    $context = app(TenantContext::class);
    $context->set(1);

    try {
        $context->runForTenant(9, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($context->currentTenantId())->toBe(1);
});
