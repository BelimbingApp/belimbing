<?php

use App\Modules\Core\User\Models\User;
use App\Modules\Core\User\Models\UserPin;

const PAGE_PINNING_FIRST_LABEL = 'First Pin';
const PAGE_PINNING_SECOND_LABEL = 'Second Pin';

test('pin toggle and reorder use URL-based identity', function (): void {
    $user = User::factory()->create();

    // Pin two items with different URLs
    $this->actingAs($user)
        ->postJson(route('pins.toggle'), [
            'label' => PAGE_PINNING_FIRST_LABEL,
            'url' => '/first',
            'icon' => null,
        ])
        ->assertOk()
        ->assertJsonPath('pinned', true);

    $this->actingAs($user)
        ->postJson(route('pins.toggle'), [
            'label' => PAGE_PINNING_SECOND_LABEL,
            'url' => '/second',
            'icon' => 'heroicon-o-table-cells',
        ])
        ->assertOk()
        ->assertJsonPath('pinned', true);

    $pins = UserPin::query()
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->get();

    expect($pins)->toHaveCount(2);
    expect($pins[0]->label)->toBe(PAGE_PINNING_FIRST_LABEL);
    expect($pins[1]->label)->toBe(PAGE_PINNING_SECOND_LABEL);

    // Reorder by id
    $this->actingAs($user)
        ->postJson(route('pins.reorder'), [
            'pins' => [
                ['id' => $pins[1]->id],
                ['id' => $pins[0]->id],
            ],
        ])
        ->assertOk();

    $reordered = UserPin::query()
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->pluck('label')
        ->all();

    expect($reordered)->toBe([PAGE_PINNING_SECOND_LABEL, PAGE_PINNING_FIRST_LABEL]);

    // Toggle same URL again unpins it
    $this->actingAs($user)
        ->postJson(route('pins.toggle'), [
            'label' => PAGE_PINNING_FIRST_LABEL,
            'url' => '/first',
            'icon' => null,
        ])
        ->assertOk()
        ->assertJsonPath('pinned', false);

    $remainingPins = UserPin::query()
        ->where('user_id', $user->id)
        ->orderBy('sort_order')
        ->get(['label', 'url']);

    expect($remainingPins)->toHaveCount(1);
    expect($remainingPins->pluck('label')->all())->toBe([PAGE_PINNING_SECOND_LABEL]);
    expect($remainingPins->pluck('url')->all())->toBe(['/second']);
});

test('duplicate URL is detected regardless of trailing slash or scheme', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('pins.toggle'), [
            'label' => 'Original',
            'url' => 'https://example.com/path/',
            'icon' => null,
        ])
        ->assertOk()
        ->assertJsonPath('pinned', true);

    // Same path without trailing slash — should toggle off (same normalized URL)
    $this->actingAs($user)
        ->postJson(route('pins.toggle'), [
            'label' => 'Original',
            'url' => 'https://example.com/path',
            'icon' => null,
        ])
        ->assertOk()
        ->assertJsonPath('pinned', false);

    expect(UserPin::query()->where('user_id', $user->id)->count())->toBe(0);
});
