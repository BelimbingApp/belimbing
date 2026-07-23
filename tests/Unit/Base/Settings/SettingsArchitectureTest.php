<?php

use App\Base\Settings\DTO\ScopeType;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('derives editable field semantics from the canonical definition', function (): void {
    $definitions = app(SettingDefinitionRegistry::class);

    foreach (config('settings.editable', []) as $groupId => $group) {
        foreach ($group['fields'] ?? [] as $field) {
            if (($field['type'] ?? 'text') === 'readonly') {
                continue;
            }

            $definition = $definitions->get($field['key']);

            expect($field['default'])->toBe($definition->default)
                ->and($field['rules'])->toBe($definition->rules)
                ->and($field['encrypted'])->toBe($definition->encrypted)
                ->and($field['scope'])->toBe($definition->scopes[0])
                ->and($field['value_type'])->toBe($definition->type)
                ->and($definition->editable)->toBe((string) $groupId)
                ->and($definition->capability)->toBeString()->not->toBe('');
        }
    }
});

it('keeps runtime parameter environment names out of application config and the environment template', function (): void {
    $legacyRuntimeKeys = [
        'APP_NAME',
        'APP_LOCALE',
        'SESSION_LIFETIME',
        'MAIL_MAILER',
        'MAIL_SCHEME',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'AI_AGENTIC_MAX_TOOL_ITERATIONS',
        'AI_PDFTOTEXT_PATH',
        'AI_LARA_PROMPT_EXTENSION_PATH',
        'AI_BASH_TOOL_ENABLED',
        'AI_WEB_SEARCH_PROVIDER',
        'AI_BROWSER_ENABLED',
        'AI_BROWSER_PATH',
        'PERF_LOG_ENABLED',
        'PERF_LOG_MIN_MS',
        'PERF_LOG_SLOW_SQL_MIN_MS',
        'PERF_LOG_RETENTION_DAYS',
        'PERF_LOG_PATH',
        'BACKUP_ENABLED',
        'BACKUP_DISK',
        'BACKUP_PATH_PREFIX',
        'BACKUP_ENCRYPTION_MODE',
        'BACKUP_KEEP_DAYS',
        'BACKUP_KEEP_COUNT',
    ];
    $runtimeKeyPattern = implode('|', array_map(
        static fn (string $key): string => preg_quote($key, '/'),
        $legacyRuntimeKeys,
    ));
    $sources = [base_path('.env.example')];

    foreach ([base_path('config'), app_path(), base_path('extensions')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php'
                || str_ends_with($file->getPathname(), 'ImportEnvironmentSettingsCommand.php')) {
                continue;
            }

            $sources[] = $file->getPathname();
        }
    }

    foreach ($sources as $source) {
        $contents = File::get($source);

        expect($contents)
            ->not->toMatch('/\benv\s*\(\s*[\'"](?:'.$runtimeKeyPattern.')[\'"]/');
    }
});

it('allows env reads only in configuration files', function (): void {
    foreach ([app_path(), base_path('extensions')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());

            if (! preg_match('/\benv\s*\(/', $contents)) {
                continue;
            }

            expect(str_replace('\\', '/', $file->getPathname()))->toContain('/Config/');
        }
    }
});

it('does not expose caller-owned defaults or encryption through SettingsService calls', function (): void {
    foreach ([app_path(), base_path('extensions'), base_path('tests')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());

            expect($contents)
                ->not->toMatch('/->(?:get|set|forget|has)\([^;]*\bdefault\s*:/s')
                ->not->toMatch('/->(?:get|set|forget|has)\([^;]*\bencrypted\s*:/s');
        }
    }
});

it('has user and company scopes without an employee settings scope', function (): void {
    expect(array_map(
        static fn (ScopeType $scope): string => $scope->value,
        ScopeType::cases(),
    ))->toBe(['company', 'user']);
});
