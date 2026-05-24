<?php

use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\AuthzErrorCode;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Exceptions\UnknownCapabilityException;
use App\Base\Foundation\Exceptions\BlbConfigurationException;
use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Base\Foundation\Exceptions\BlbIntegrationException;
use App\Modules\Core\AI\Enums\AIErrorCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('renders BLB data contract exceptions as structured 422 JSON in debug mode', function (): void {
    config()->set('app.debug', true);

    Route::get('/_test/blb-exception/data-contract', function (): void {
        throw new BlbDataContractException(
            'Invalid Agent identifier type.',
            AIErrorCode::LARA_AGENT_ID_TYPE_INVALID,
            ['employee_id' => 'abc']
        );
    });

    $response = $this->getJson('/_test/blb-exception/data-contract');

    $response->assertStatus(422)
        ->assertJsonPath('reason_code', AIErrorCode::LARA_AGENT_ID_TYPE_INVALID->value)
        ->assertJsonPath('message', 'Invalid Agent identifier type.')
        ->assertJsonPath('context.employee_id', 'abc');
});

it('hides BLB exception internals in non-debug JSON responses', function (): void {
    config()->set('app.debug', false);

    Route::get('/_test/blb-exception/non-debug', function (): void {
        throw new BlbIntegrationException(
            'Sensitive upstream failure detail.',
            AIErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED,
            ['upstream' => 'provider-a']
        );
    });

    $response = $this->getJson('/_test/blb-exception/non-debug');

    $response->assertStatus(500)
        ->assertJsonPath('reason_code', AIErrorCode::LARA_PROMPT_CONTEXT_ENCODE_FAILED->value)
        ->assertJsonPath('message', 'An internal framework error occurred.');

    expect($response->json())->not->toHaveKey('context');
});

it('reports BLB exceptions with structured log metadata', function (): void {
    config()->set('app.debug', true);
    Log::spy();

    Route::get('/_test/blb-exception/logging', function (): void {
        throw new BlbConfigurationException(
            'Lara base prompt file missing.',
            AIErrorCode::LARA_PROMPT_RESOURCE_MISSING,
            ['path' => '/tmp/system_prompt.md']
        );
    });

    $this->getJson('/_test/blb-exception/logging')->assertStatus(500);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'BLB framework exception'
                && $context['reason_code'] === AIErrorCode::LARA_PROMPT_RESOURCE_MISSING->value
                && $context['context'] === ['path' => '/tmp/system_prompt.md']
                && is_string($context['exception']);
        })
        ->atLeast()
        ->once();
});

it('renders authz denial exceptions as structured 403 JSON', function (): void {
    config()->set('app.debug', true);

    Route::get('/_test/blb-exception/authz-denied', function (): void {
        throw new AuthorizationDeniedException(
            AuthorizationDecision::deny(
                AuthorizationReasonCode::DENIED_MISSING_CAPABILITY,
                ['capability-policy']
            )
        );
    });

    $response = $this->getJson('/_test/blb-exception/authz-denied');

    $response->assertStatus(403)
        ->assertJsonPath('reason_code', AuthzErrorCode::AUTHZ_DENIED->value);
});

it('renders unknown capability exceptions as structured 422 JSON', function (): void {
    config()->set('app.debug', true);

    Route::get('/_test/blb-exception/authz-unknown-capability', function (): void {
        throw UnknownCapabilityException::fromKey('core.user.manage');
    });

    $response = $this->getJson('/_test/blb-exception/authz-unknown-capability');

    $response->assertStatus(422)
        ->assertJsonPath('reason_code', AuthzErrorCode::AUTHZ_UNKNOWN_CAPABILITY->value);
});
