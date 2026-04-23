<?php

use App\Base\AI\Services\ModelCatalogQueryService;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\Foundation\Exceptions\BlbDataContractException;
use Tests\TestCase;

uses(TestCase::class);

it('filters catalog models with AND/OR boolean expressions', function (): void {
    $catalog = Mockery::mock(ModelCatalogService::class);
    $catalog->shouldReceive('getProviders')->once()->andReturn([
        'openai' => [
            'display_name' => 'OpenAI',
            'category' => ['leading-lab'],
            'region' => ['global'],
        ],
        'anthropic' => [
            'display_name' => 'Anthropic',
            'category' => ['leading-lab'],
            'region' => ['global'],
        ],
    ]);
    $catalog->shouldReceive('getModels')->with('openai')->once()->andReturn([
        'gpt-5.3' => [
            'id' => 'gpt-5.3',
            'name' => 'GPT-5.3',
            'family' => 'gpt',
            'reasoning' => true,
            'tool_call' => true,
            'open_weights' => false,
            'modalities' => [
                'input' => ['text'],
                'output' => ['text'],
            ],
        ],
        'gpt-image-1' => [
            'id' => 'gpt-image-1',
            'name' => 'GPT Image 1',
            'family' => 'gpt',
            'reasoning' => false,
            'tool_call' => false,
            'open_weights' => false,
            'modalities' => [
                'input' => ['image'],
                'output' => ['image'],
            ],
        ],
    ]);
    $catalog->shouldReceive('getModels')->with('anthropic')->once()->andReturn([
        'claude-opus-4.6' => [
            'id' => 'claude-opus-4.6',
            'name' => 'Claude Opus 4.6',
            'family' => 'claude',
            'reasoning' => true,
            'tool_call' => true,
            'open_weights' => false,
            'modalities' => [
                'input' => ['text'],
                'output' => ['text'],
            ],
        ],
    ]);

    $service = new ModelCatalogQueryService($catalog);
    $matches = $service->query('reasoning:true AND input:text AND (family:gpt OR family:claude)');

    expect($matches)->toHaveCount(2)
        ->and(collect($matches)->pluck('model')->all())
        ->toEqualCanonicalizing(['gpt-5.3', 'claude-opus-4.6']);
});

it('throws data contract exception for invalid filter syntax', function (): void {
    $catalog = Mockery::mock(ModelCatalogService::class);
    $service = new ModelCatalogQueryService($catalog);

    expect(fn () => $service->query('reasoning:true AND ???'))
        ->toThrow(BlbDataContractException::class);
});

it('supports tool_call as an alias for tools filters', function (): void {
    $catalog = Mockery::mock(ModelCatalogService::class);
    $catalog->shouldReceive('getProviders')->once()->andReturn([
        'openai' => [
            'display_name' => 'OpenAI',
            'category' => ['leading-lab'],
            'region' => ['global'],
        ],
    ]);
    $catalog->shouldReceive('getModels')->with('openai')->once()->andReturn([
        'gpt-5.3' => [
            'id' => 'gpt-5.3',
            'name' => 'GPT-5.3',
            'family' => 'gpt',
            'reasoning' => true,
            'tool_call' => true,
            'open_weights' => false,
            'modalities' => [
                'input' => ['text'],
                'output' => ['text'],
            ],
        ],
        'gpt-image-1' => [
            'id' => 'gpt-image-1',
            'name' => 'GPT Image 1',
            'family' => 'gpt',
            'reasoning' => false,
            'tool_call' => false,
            'open_weights' => false,
            'modalities' => [
                'input' => ['image'],
                'output' => ['image'],
            ],
        ],
    ]);

    $service = new ModelCatalogQueryService($catalog);
    $matches = $service->query('tool_call:true');

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['model'])->toBe('gpt-5.3');
});

it('throws data contract exception for mismatched parentheses', function (): void {
    $catalog = Mockery::mock(ModelCatalogService::class);
    $service = new ModelCatalogQueryService($catalog);

    expect(fn () => $service->query('(reasoning:true AND family:gpt'))
        ->toThrow(BlbDataContractException::class);
});
