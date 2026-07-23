<?php

namespace App\Base\Settings\Console\Commands;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:settings:import-environment')]
final class ImportEnvironmentSettingsCommand extends Command
{
    protected $signature = 'blb:settings:import-environment
                            {--apply : Persist eligible values; without this flag the command is a preview}
                            {--force : Replace settings that already have an explicit global value}
                            {--file= : Read an alternate env file instead of the project .env}';

    protected $description = 'Preview or import legacy runtime parameters from .env into canonical global settings';

    /**
     * @var array<string, string>
     */
    private const array KEY_MAP = [
        'APP_NAME' => 'system.identity.name',
        'APP_LOCALE' => 'ui.locale',
        'SESSION_LIFETIME' => 'session.lifetime_minutes',
        'MAIL_MAILER' => 'mail.mailer',
        'MAIL_SCHEME' => 'mail.smtp.scheme',
        'MAIL_HOST' => 'mail.smtp.host',
        'MAIL_PORT' => 'mail.smtp.port',
        'MAIL_USERNAME' => 'mail.smtp.username',
        'MAIL_PASSWORD' => 'mail.smtp.password',
        'MAIL_FROM_ADDRESS' => 'mail.from.address',
        'MAIL_FROM_NAME' => 'mail.from.name',
        'AI_AGENTIC_MAX_TOOL_ITERATIONS' => 'ai.llm.agentic.max_tool_rounds',
        'AI_PDFTOTEXT_PATH' => 'ai.tools.document_analysis.pdftotext_path',
        'AI_LARA_PROMPT_EXTENSION_PATH' => 'ai.lara.prompt_extension_path',
        'AI_BASH_TOOL_ENABLED' => 'ai.tools.bash.enabled',
        'AI_WEB_SEARCH_PROVIDER' => 'ai.tools.web_search.provider',
        'AI_BROWSER_ENABLED' => 'ai.tools.browser.enabled',
        'AI_BROWSER_PATH' => 'ai.tools.browser.executable_path',
        'PERF_LOG_ENABLED' => 'perf.enabled',
        'PERF_LOG_MIN_MS' => 'perf.min_ms',
        'PERF_LOG_SLOW_SQL_MIN_MS' => 'perf.slow_sql_min_ms',
        'PERF_LOG_RETENTION_DAYS' => 'perf.retention_days',
        'PERF_LOG_PATH' => 'perf.path',
        'BACKUP_ENABLED' => 'backup.enabled',
        'BACKUP_DISK' => 'backup.disk',
        'BACKUP_PATH_PREFIX' => 'backup.path_prefix',
        'BACKUP_ENCRYPTION_MODE' => 'backup.encryption.mode',
        'BACKUP_KEEP_DAYS' => 'backup.retention.keep_days',
        'BACKUP_KEEP_COUNT' => 'backup.retention.keep_count',
    ];

    public function handle(
        SettingsService $settings,
        SettingDefinitionRegistry $definitions,
    ): int {
        $requestedPath = $this->option('file');
        $path = is_string($requestedPath) && trim($requestedPath) !== ''
            ? $requestedPath
            : base_path('.env');

        if (! is_file($path)) {
            $this->components->error('The requested environment file does not exist.');

            return self::FAILURE;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            $this->components->error('The project .env file could not be read.');

            return self::FAILURE;
        }

        $environment = Dotenv::parse($contents);
        $rows = [];
        $imports = [];
        $force = (bool) $this->option('force');

        foreach (self::KEY_MAP as $environmentKey => $settingKey) {
            if (! array_key_exists($environmentKey, $environment)) {
                continue;
            }

            $definition = $definitions->get($settingKey);
            try {
                $value = $this->coerce($environment[$environmentKey], $definition->type);
                if ($value !== null) {
                    $definition->assertStorableValue($value);
                }
            } catch (\InvalidArgumentException) {
                $rows[] = [$environmentKey, $settingKey, $definition->encrypted ? 'secret' : $definition->type, 'skip invalid'];

                continue;
            }

            $existing = $settings->has($settingKey);
            $action = $value === null
                ? 'skip empty'
                : ($existing && ! $force ? 'keep existing' : ((bool) $this->option('apply') ? 'import' : 'would import'));

            $rows[] = [$environmentKey, $settingKey, $definition->encrypted ? 'secret' : $definition->type, $action];

            if ($value !== null && (! $existing || $force)) {
                $imports[$settingKey] = $value;
            }
        }

        if ($rows === []) {
            $this->components->info('No legacy runtime parameters were found in .env.');

            return self::SUCCESS;
        }

        $this->table(['Environment key', 'Setting key', 'Value type', 'Action'], $rows);

        if (! $this->option('apply')) {
            $this->components->warn('Preview only. Re-run with --apply to persist these values.');

            return self::SUCCESS;
        }

        foreach ($imports as $key => $value) {
            $settings->set($key, $value);
        }

        $this->components->info(count($imports).' setting(s) imported. Remove the corresponding legacy keys from .env after verification.');

        return self::SUCCESS;
    }

    private function coerce(?string $value, string $type): mixed
    {
        if ($value === null || trim($value) === '' || strtolower(trim($value)) === 'null') {
            return null;
        }

        $coerced = match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            default => $value,
        };

        if ($coerced === null) {
            throw new \InvalidArgumentException("Invalid {$type} environment value.");
        }

        return $coerced;
    }
}
