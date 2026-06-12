<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\Livewire\Setup\Lara;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use App\Modules\Core\AI\Services\LaraInteractiveToolSet;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('ai.workspace_path', storage_path('framework/testing/lara-setup-tools-'.Str::random(16)));
});

afterEach(function (): void {
    $workspacePath = config('ai.workspace_path');

    if (is_string($workspacePath)) {
        File::deleteDirectory($workspacePath);
    }
});

test('lara setup shows the fixed default interactive tools and registered additional tools', function (): void {
    $user = createAdminUser();

    $this->actingAs($user)
        ->get(route('admin.setup.lara'))
        ->assertOk()
        ->assertSee('Interactive Tools')
        ->assertSee('Enabled in Lara Chat')
        ->assertSee('Available to Add')
        ->assertSee('active_page_snapshot')
        ->assertSee('browser');

    Livewire::test(Lara::class)
        ->assertViewHas('enabledToolRows', function (array $rows): bool {
            return array_column($rows, 'name') === ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES
                && collect($rows)->every(fn (array $row): bool => $row['isDefault'] === true);
        })
        ->assertViewHas('availableToolRows', function (array $rows): bool {
            return in_array('browser', array_column($rows, 'name'), true);
        });
});

test('lara setup can add and remove extra interactive tools without changing defaults', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    $toolSet = app(LaraInteractiveToolSet::class);

    expect($toolSet->enabledToolNames())->toBe(ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES);

    Livewire::test(Lara::class)
        ->call('toggleExtraTool', 'browser');

    expect($toolSet->enabledToolNames())->toBe([
        ...ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES,
        'browser',
    ]);

    Livewire::test(Lara::class)
        ->assertViewHas('enabledToolRows', function (array $rows): bool {
            return in_array('browser', array_column($rows, 'name'), true);
        })
        ->assertViewHas('availableToolRows', function (array $rows): bool {
            return ! in_array('browser', array_column($rows, 'name'), true);
        });

    Livewire::test(Lara::class)
        ->call('toggleExtraTool', 'read');

    expect(app(SettingsService::class)->get('ai.lara.interactive_extra_tool_names'))->toBe(['browser']);

    Livewire::test(Lara::class)
        ->call('toggleExtraTool', 'browser');

    expect($toolSet->enabledToolNames())->toBe(ChatTurnRunner::DEFAULT_INTERACTIVE_AGENT_TOOL_NAMES);
});
