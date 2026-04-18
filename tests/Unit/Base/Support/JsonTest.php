<?php

use App\Base\Support\Json;
use Tests\TestCase;

uses(TestCase::class);

it('decodes a JSON object into an associative array', function (): void {
    expect(Json::decodeArray('{"name":"Lara","active":true}'))
        ->toBe([
            'name' => 'Lara',
            'active' => true,
        ]);
});

it('returns null for blank or invalid JSON payloads', function (): void {
    expect(Json::decodeArray(''))->toBeNull()
        ->and(Json::decodeArray('not json'))->toBeNull();
});

it('returns null for scalar JSON payloads', function (): void {
    expect(Json::decodeArray('"text"'))->toBeNull()
        ->and(Json::decodeArray('123'))->toBeNull();
});

it('extracts brace-bounded object spans for salvage', function (): void {
    expect(Json::braceBoundedObjectCandidates(''))->toBe([])
        ->and(Json::braceBoundedObjectCandidates('no braces'))->toBe([])
        ->and(Json::braceBoundedObjectCandidates('x {"a":1} y'))->toBe(['{"a":1}'])
        ->and(Json::braceBoundedObjectCandidates('{"x":{"y":1}}'))->toBe(['{"x":{"y":1}}'])
        ->and(Json::braceBoundedObjectCandidates('{"a":1}{"b":2}'))->toBe(['{"a":1}', '{"b":2}']);
});
