<?php

use App\Modules\Core\AI\Services\ChatToolProfileRegistry;

const CHAT_CORE_TOOLS = [
    'active_page_snapshot',
    'guide',
    'memory_get',
    'memory_search',
    'navigate',
    'system_info',
    'visible_nav_menu',
    'write_js',
];

const CHAT_DATA_EXTRA_TOOLS = ['artisan', 'edit_data', 'query_data'];

const CHAT_ACTION_EXTRA_TOOLS = [
    'agent_list',
    'delegate_task',
    'message',
    'notification',
    'schedule_task',
    'ticket_update',
];

it('resolves chat-core profile with navigational and informational tools', function (): void {
    $registry = new ChatToolProfileRegistry;
    $tools = $registry->resolve('chat-core');

    expect($tools)->not->toBeNull()
        ->and($tools)->toBe(CHAT_CORE_TOOLS);
});

it('resolves chat-data profile inheriting chat-core tools', function (): void {
    $registry = new ChatToolProfileRegistry;
    $tools = $registry->resolve('chat-data');

    expect($tools)->not->toBeNull();

    foreach (CHAT_CORE_TOOLS as $coreTool) {
        expect($tools)->toContain($coreTool);
    }

    foreach (CHAT_DATA_EXTRA_TOOLS as $dataTool) {
        expect($tools)->toContain($dataTool);
    }
});

it('resolves chat-action profile inheriting chat-data and chat-core tools', function (): void {
    $registry = new ChatToolProfileRegistry;
    $tools = $registry->resolve('chat-action');

    expect($tools)->not->toBeNull();

    foreach (CHAT_CORE_TOOLS as $coreTool) {
        expect($tools)->toContain($coreTool);
    }

    foreach (CHAT_DATA_EXTRA_TOOLS as $dataTool) {
        expect($tools)->toContain($dataTool);
    }

    foreach (CHAT_ACTION_EXTRA_TOOLS as $actionTool) {
        expect($tools)->toContain($actionTool);
    }

    expect($tools)->toHaveCount(count(CHAT_CORE_TOOLS) + count(CHAT_DATA_EXTRA_TOOLS) + count(CHAT_ACTION_EXTRA_TOOLS));
});

it('resolves chat-full as null to allow all tools', function (): void {
    $registry = new ChatToolProfileRegistry;

    expect($registry->resolve('chat-full'))->toBeNull();
});

it('returns null for an unknown profile key', function (): void {
    $registry = new ChatToolProfileRegistry;

    expect($registry->resolve('nonexistent'))->toBeNull();
});

it('exposes all profile keys', function (): void {
    $registry = new ChatToolProfileRegistry;
    $keys = $registry->profileKeys();

    expect($keys)->toContain('chat-core')
        ->and($keys)->toContain('chat-data')
        ->and($keys)->toContain('chat-action')
        ->and($keys)->toContain('chat-full');
});

it('defaults to chat-core as the default profile constant', function (): void {
    expect(ChatToolProfileRegistry::DEFAULT_PROFILE)->toBe('chat-core');
});
