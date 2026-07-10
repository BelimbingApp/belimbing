<?php

namespace App\Modules\Core\AI\Services\HeadlessCli;

use App\Modules\Core\AI\DTO\HeadlessCliRunResult;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Employee\Models\Employee;
use RuntimeException;
use Throwable;

class HeadlessCliExecutor
{
    public function __construct(private readonly HeadlessCliProcessExecutor $processExecutor) {}

    public function run(ScheduleDefinition $schedule): HeadlessCliRunResult
    {
        [$provider, $model, $source] = $this->resolveIdentity($schedule);
        $config = $this->providerConfig($provider);
        $command = $this->command((string) $config['command'], $schedule, $provider, $model);

        $result = $this->processExecutor->run($command, (int) ($config['timeout_seconds'] ?? 3600));
        $parsed = $this->parseOutput($result['stdout']);

        return new HeadlessCliRunResult(
            exitCode: $result['exit_code'],
            stdout: $result['stdout'],
            stderr: $result['stderr'],
            result: $parsed['result'],
            costUsd: $parsed['cost_usd'],
            usage: $parsed['usage'],
            provider: $provider,
            model: $model,
            identitySource: $source,
            command: $command,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function providerConfig(string $provider): array
    {
        $providers = config('ai-headless.providers', []);

        return is_array($providers[$provider] ?? null)
            ? $providers[$provider]
            : throw new RuntimeException('Unknown headless provider ['.$provider.']; add it to ai-headless.providers.');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public function resolveIdentity(ScheduleDefinition $schedule): array
    {
        $provider = $schedule->headless_provider;
        $model = $schedule->headless_model;

        if ($provider !== null && $provider !== 'auto' && $model !== null && $model !== 'auto') {
            return [$provider, $model, 'pinned on this schedule'];
        }

        try {
            $config = app(ConfigResolver::class)->readTaskConfig(Employee::LARA_ID, 'research') ?? [];
            $providerName = is_string($config['provider'] ?? null) ? $config['provider'] : null;
            $configuredModel = is_string($config['model'] ?? null) ? $config['model'] : null;

            if ($providerName !== null && $configuredModel !== null) {
                $family = $this->cliFamily($providerName, $configuredModel);

                if ($family !== null) {
                    return [$family, $configuredModel, 'AI Task Models: Research ('.$providerName.' / '.$configuredModel.')'];
                }

                return [
                    (string) config('ai-headless.fallback_provider', 'anthropic'),
                    (string) config('ai-headless.fallback_model', 'claude-sonnet-5'),
                    'fallback: no CLI template for provider "'.$providerName.'"',
                ];
            }
        } catch (Throwable) {
            // AI model settings are optional for unattended CLI schedules.
        }

        return [
            (string) config('ai-headless.fallback_provider', 'anthropic'),
            (string) config('ai-headless.fallback_model', 'claude-sonnet-5'),
            'fallback: no Research model configured on AI Task Models',
        ];
    }

    public function cliFamily(string $providerName, string $model): ?string
    {
        $haystack = strtolower($providerName.' '.$model);
        $available = array_keys((array) config('ai-headless.providers', []));

        $family = match (true) {
            str_contains($haystack, 'anthropic'), str_contains($haystack, 'claude') => 'anthropic',
            str_contains($haystack, 'openai'), str_contains($haystack, 'codex'), str_contains($haystack, 'gpt') => 'openai',
            default => null,
        };

        return in_array($family, $available, true) ? $family : null;
    }

    private function command(string $template, ScheduleDefinition $schedule, string $provider, string $model): string
    {
        $attribution = $provider.'/'.$model;
        $preamble = (string) data_get($schedule->meta, 'attribution_preamble', config('ai-headless.attribution_preamble', ''));
        $prompt = str_replace('{attribution}', $attribution, $preamble).$schedule->execution_payload;

        return str_replace(
            ['{prompt}', '{model}'],
            [escapeshellarg($prompt), escapeshellarg($model)],
            $template,
        );
    }

    /**
     * @return array{result: string|null, cost_usd: float|null, usage: array<string, mixed>|null}
     */
    private function parseOutput(string $stdout): array
    {
        $decoded = json_decode(trim($stdout), true);

        if (! is_array($decoded)) {
            return ['result' => null, 'cost_usd' => null, 'usage' => null];
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null;

        return [
            'result' => is_string($decoded['result'] ?? null) ? $decoded['result'] : null,
            'cost_usd' => is_numeric($decoded['total_cost_usd'] ?? null) ? (float) $decoded['total_cost_usd'] : null,
            'usage' => $usage,
        ];
    }
}
