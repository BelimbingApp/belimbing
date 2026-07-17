<?php

use App\Base\AI\Contracts\ProvidesDisplaySummary;
use App\Modules\Core\AI\Tools\ActivePageSnapshotTool;
use App\Modules\Core\AI\Tools\BashTool;
use App\Modules\Core\AI\Tools\EditTool;
use App\Modules\Core\AI\Tools\LoadSkillTool;
use App\Modules\Core\AI\Tools\ReadTool;
use App\Modules\Core\AI\Tools\SearchTool;
use App\Modules\Core\AI\Tools\WebFetchTool;
use App\Modules\Core\AI\Tools\WebSearchTool;
use Tests\TestCase;

uses(TestCase::class);

test('default interactive tools summarize invocations in plain language', function (): void {
    expect(app(SearchTool::class)->displaySummary(['query' => 'ModelDiscovery', 'mode' => 'content']))
        ->toBe('Search content for "ModelDiscovery"');

    expect(app(ReadTool::class)->displaySummary(['file_path' => 'app/Models/User.php', 'offset' => 200]))
        ->toBe('Read app/Models/User.php @200');

    expect(app(ReadTool::class)->displaySummary(['target' => 'data', 'query' => 'SELECT id FROM users']))
        ->toBe('Query data: SELECT id FROM users');

    expect(app(EditTool::class)->displaySummary(['file_path' => 'config/app.php', 'operation' => 'replace']))
        ->toBe('Replace in config/app.php');

    expect(app(BashTool::class)->displaySummary(['command' => 'php artisan migrate:status']))
        ->toBe('$ php artisan migrate:status');

    expect(app(ActivePageSnapshotTool::class)->displaySummary([]))
        ->toBe('Inspect the current page');

    expect(app(LoadSkillTool::class)->displaySummary(['skill_id' => 'core.verify']))
        ->toBe('Load skill core.verify');

    expect(app(WebSearchTool::class)->displaySummary(['query' => 'laravel livewire']))
        ->toBe('Search the web for "laravel livewire"');

    expect(app(WebFetchTool::class)->displaySummary(['url' => 'https://example.com/docs']))
        ->toBe('Fetch https://example.com/docs');
});

test('display summaries tolerate malformed arguments', function (): void {
    foreach ([SearchTool::class, ReadTool::class, EditTool::class, BashTool::class, LoadSkillTool::class, WebSearchTool::class, WebFetchTool::class] as $toolClass) {
        $tool = app($toolClass);

        expect($tool)->toBeInstanceOf(ProvidesDisplaySummary::class)
            ->and($tool->displaySummary([]))->toBeString()->not->toBe('')
            ->and($tool->displaySummary(['query' => ['nested' => 'array'], 'file_path' => 42, 'command' => null]))->toBeString();
    }
});
