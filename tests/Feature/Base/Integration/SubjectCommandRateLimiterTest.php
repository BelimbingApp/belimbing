<?php

use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Integration\Enums\SubjectCommandExecutionState;
use App\Base\Integration\Services\SubjectCommandRateLimiter;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;

it('refuses a configured tenant and subject burst before dispatch', function (): void {
    config()->set('integration.subject_command_limits', [
        'employee.update' => ['max_attempts' => 2, 'decay_seconds' => 60],
    ]);
    app(TenantContext::class)->set(41);
    $dispatched = 0;
    $limiter = app(SubjectCommandRateLimiter::class);
    $dispatch = function () use (&$dispatched): string {
        $dispatched++;

        return 'accepted';
    };

    $first = $limiter->execute('employee.update', 'employee-9', $dispatch);
    $second = $limiter->execute('employee.update', 'employee-9', $dispatch);
    $refused = $limiter->execute('employee.update', 'employee-9', $dispatch);

    expect($first->state)->toBe(SubjectCommandExecutionState::Executed)
        ->and($first->value)->toBe('accepted')
        ->and($second->state)->toBe(SubjectCommandExecutionState::Executed)
        ->and($refused->state)->toBe(SubjectCommandExecutionState::RefusedBeforeDispatch)
        ->and($refused->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($refused->wasDispatched())->toBeFalse()
        ->and($dispatched)->toBe(2);

    $connectorOutcome = match ($refused->state) {
        SubjectCommandExecutionState::RefusedBeforeDispatch => 'not_delivered',
        SubjectCommandExecutionState::Executed => 'provider_decides',
    };

    expect($connectorOutcome)->toBe('not_delivered')
        ->not->toBe('unknown');
});

it('isolates limits by tenant subject and operation while undeclared reads stay unlimited', function (): void {
    config()->set('integration.subject_command_limits', [
        'employee.update' => ['max_attempts' => 1, 'decay_seconds' => 60],
    ]);
    $context = app(TenantContext::class);
    $limiter = app(SubjectCommandRateLimiter::class);

    $context->set(51);
    expect($limiter->execute('employee.update', 'employee-a', fn (): string => 'tenant-51-a')->value)
        ->toBe('tenant-51-a')
        ->and($limiter->execute('employee.update', 'employee-a', fn (): string => 'unexpected')->wasDispatched())
        ->toBeFalse()
        ->and($limiter->execute('employee.update', 'employee-b', fn (): string => 'tenant-51-b')->value)
        ->toBe('tenant-51-b')
        ->and($limiter->execute('employee.cancel', 'employee-a', fn (): string => 'other-command')->value)
        ->toBe('other-command');

    $context->set(52);
    expect($limiter->execute('employee.update', 'employee-a', fn (): string => 'tenant-52-a')->value)
        ->toBe('tenant-52-a');

    for ($attempt = 0; $attempt < 3; $attempt++) {
        expect($limiter->execute('employee.read', 'employee-a', fn (): string => 'read')->value)
            ->toBe('read');
    }
});

it('consumes admission before dispatch when the command outcome becomes unknown', function (): void {
    config()->set('integration.subject_command_limits', [
        'employee.update' => ['max_attempts' => 1, 'decay_seconds' => 60],
    ]);
    app(TenantContext::class)->set(61);
    $limiter = app(SubjectCommandRateLimiter::class);

    expect(fn () => $limiter->execute(
        'employee.update',
        'employee-timeout',
        fn () => throw new RuntimeException('answer lost'),
    ))->toThrow(RuntimeException::class, 'answer lost');

    $afterTimeout = $limiter->execute(
        'employee.update',
        'employee-timeout',
        fn (): string => 'must not dispatch',
    );

    expect($afterTimeout->state)->toBe(SubjectCommandExecutionState::RefusedBeforeDispatch)
        ->and($afterTimeout->wasDispatched())->toBeFalse();
});

it('fails closed without tenant context', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => app(SubjectCommandRateLimiter::class)->execute(
        'employee.update',
        'employee-1',
        fn (): string => 'must not dispatch',
    ))->toThrow(TenantContextMissingException::class);
});

it('fails closed on a malformed declared limit', function (): void {
    config()->set('integration.subject_command_limits', [
        'employee.update' => ['max_attempts' => 0, 'decay_seconds' => 60],
    ]);
    app(TenantContext::class)->set(71);

    expect(fn () => app(SubjectCommandRateLimiter::class)->execute(
        'employee.update',
        'employee-1',
        fn (): string => 'must not dispatch',
    ))->toThrow(BlbConfigurationException::class, 'requires positive integer');
});
