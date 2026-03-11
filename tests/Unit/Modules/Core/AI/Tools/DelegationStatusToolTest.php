<?php

use App\Modules\Core\AI\Tools\DelegationStatusTool;
use Tests\Support\AssertsToolBehavior;
use Tests\TestCase;

uses(TestCase::class, AssertsToolBehavior::class);

beforeEach(function () {
    $this->tool = new DelegationStatusTool;
});

describe('tool metadata', function () {
    it('has the expected metadata', function () {
        $this->assertToolMetadata(
            $this->tool,
            'delegation_status',
            'ai.tool_delegation_status.execute',
            ['dispatch_id'],
            ['dispatch_id'],
        );
    });
});

describe('input validation', function () {
    it('rejects missing or empty dispatch_id', function () {
        $this->assertRejectsMissingAndEmptyStringArgument('dispatch_id');
    });

    it('rejects invalid dispatch_id format', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'invalid_id']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });

    it('rejects dispatch_id with prefix only', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'dw_dispatch_']);
        expect($result)->toContain('Error')
            ->and($result)->toContain('Invalid dispatch_id format');
    });
});

describe('status lookup', function () {
    it('returns queued status for valid dispatch_id', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'dw_dispatch_abc123xyz']);
        $data = json_decode($result, true);

        expect($data)->not->toBeNull()
            ->and($data['dispatch_id'])->toBe('dw_dispatch_abc123xyz')
            ->and($data['status'])->toBe('queued')
            ->and($data)->toHaveKey('checked_at')
            ->and($data)->toHaveKey('message');
    });

    it('returns valid JSON', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'dw_dispatch_test123']);

        expect(json_decode($result, true))->not->toBeNull();
    });

    it('returns pretty-printed JSON', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'dw_dispatch_test123']);

        expect($result)->toContain("\n");
    });

    it('preserves dispatch_id in response', function () {
        $dispatchId = 'dw_dispatch_unique42';
        $result = (string) $this->tool->execute(['dispatch_id' => $dispatchId]);
        $data = json_decode($result, true);

        expect($data['dispatch_id'])->toBe($dispatchId);
    });

    it('includes checked_at timestamp', function () {
        $result = (string) $this->tool->execute(['dispatch_id' => 'dw_dispatch_time']);
        $data = json_decode($result, true);

        expect($data['checked_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');
    });
});
