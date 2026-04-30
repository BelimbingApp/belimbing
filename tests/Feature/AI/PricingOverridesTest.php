<?php

use App\Modules\Core\AI\Livewire\PricingOverrides;
use App\Modules\Core\AI\Models\AiPricingOverride;
use App\Modules\Core\AI\Services\Pricing\PricingSourceRegistry;
use Livewire\Livewire;

test('pricing overrides page renders for AI admins', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.ai.pricing-overrides'))
        ->assertOk()
        ->assertSee('Pricing Overrides')
        ->assertSee('New Override');
});

test('pricing overrides can be created edited and deleted', function (): void {
    $user = createAdminUser();

    Livewire::actingAs($user)
        ->test(PricingOverrides::class)
        ->set('provider', 'openai')
        ->set('model', 'gpt-5.4')
        ->set('inputUsdPerMillionTokens', '1')
        ->set('cachedInputUsdPerMillionTokens', '0.1')
        ->set('outputUsdPerMillionTokens', '2')
        ->set('reason', 'enterprise contract')
        ->call('saveOverride')
        ->assertHasNoErrors()
        ->assertSet('model', '');

    $override = AiPricingOverride::query()->firstOrFail();

    expect($override->provider)->toBe('openai')
        ->and($override->model)->toBe('gpt-5.4')
        ->and($override->input_usd_per_million_tokens)->toBe('1.000000000000')
        ->and($override->cached_input_usd_per_million_tokens)->toBe('0.100000000000')
        ->and($override->output_usd_per_million_tokens)->toBe('2.000000000000')
        ->and($override->created_by)->toBe($user->id);

    Livewire::actingAs($user)
        ->test(PricingOverrides::class)
        ->call('editOverride', $override->id)
        ->assertSet('model', 'gpt-5.4')
        ->set('outputUsdPerMillionTokens', '3')
        ->set('reason', 'updated contract')
        ->call('saveOverride')
        ->assertHasNoErrors();

    $override->refresh();

    expect($override->output_usd_per_million_tokens)->toBe('3.000000000000')
        ->and($override->reason)->toBe('updated contract')
        ->and(app(PricingSourceRegistry::class)->resolve('openai', 'gpt-5.4')?->source)->toBe('override');

    Livewire::actingAs($user)
        ->test(PricingOverrides::class)
        ->call('deleteOverride', $override->id);

    expect(AiPricingOverride::query()->count())->toBe(0);
});

test('pricing overrides reject duplicate provider model pairs before hitting the database', function (): void {
    $user = createAdminUser();

    AiPricingOverride::query()->create([
        'provider' => null,
        'model' => 'shared-model',
        'input_usd_per_million_tokens' => '1.000000000000',
        'cached_input_usd_per_million_tokens' => null,
        'output_usd_per_million_tokens' => '2.000000000000',
        'reason' => 'baseline',
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(PricingOverrides::class)
        ->set('provider', '')
        ->set('model', 'shared-model')
        ->set('inputUsdPerMillionTokens', '1')
        ->set('outputUsdPerMillionTokens', '2')
        ->call('saveOverride')
        ->assertHasErrors(['model']);

    expect(AiPricingOverride::query()->count())->toBe(1);
});
