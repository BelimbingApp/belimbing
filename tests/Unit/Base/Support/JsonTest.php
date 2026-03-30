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
