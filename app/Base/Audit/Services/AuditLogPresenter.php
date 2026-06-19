<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Support\Str;

final class AuditLogPresenter
{
    /**
     * @return array{source: string, summary: string, context: string|null, result: string, variant: string, diagnostic: bool}
     */
    public function actionSummary(AuditAction $action): array
    {
        $payload = $this->payload($action);

        if (($payload['semantic'] ?? false) === true) {
            return $this->semanticSummary($action, $payload);
        }

        if ($action->event === 'http.request') {
            return $this->httpSummary($action, $payload);
        }

        if (str_starts_with($action->event, 'auth.')) {
            return $this->authSummary($action, $payload);
        }

        if ($action->event === 'console.command') {
            return $this->consoleSummary($payload);
        }

        if (str_starts_with($action->event, 'queue.job.')) {
            return $this->queueSummary($action, $payload);
        }

        if (str_starts_with($action->event, 'domain.')) {
            return $this->domainSummary($action, $payload);
        }

        return [
            'source' => __('Action'),
            'summary' => $this->humanizeEvent($action->event),
            'context' => null,
            'result' => __('Recorded'),
            'variant' => 'default',
            'diagnostic' => false,
        ];
    }

    public function actorLabel(object $row): string
    {
        $actorName = data_get($row, 'actor_name');
        if (is_string($actorName) && $actorName !== '') {
            return $actorName;
        }

        $actorType = (string) data_get($row, 'actor_type', '');
        $actorId = data_get($row, 'actor_id');

        if ($actorType === PrincipalType::GUEST->value || $actorId === null || (int) $actorId === 0) {
            return match ($actorType) {
                PrincipalType::CONSOLE->value => __('Console'),
                PrincipalType::SCHEDULER->value => __('Scheduler'),
                PrincipalType::QUEUE->value => __('Queue'),
                PrincipalType::GUEST->value => __('Guest'),
                default => $actorType !== '' ? Str::headline($actorType) : __('System'),
            };
        }

        return $actorType !== '' ? $actorType.'#'.$actorId : __('System');
    }

    public function formatTrace(?string $traceId): string
    {
        if ($traceId === null || $traceId === '') {
            return '—';
        }

        $normalized = $this->normalizeTrace($traceId);

        if (strlen($normalized) !== 12) {
            return $traceId;
        }

        return substr($normalized, 0, 4).'-'.substr($normalized, 4, 4).'-'.substr($normalized, 8, 4);
    }

    public function normalizeTrace(string $traceId): string
    {
        return strtoupper(str_replace('-', '', trim($traceId)));
    }

    public function mutationLabel(AuditMutation $mutation): string
    {
        $subjectName = $mutation->subject_name;
        $subjectId = $mutation->subject_id;

        if (is_string($subjectName) && $subjectName !== '' && $subjectId !== null) {
            $label = Str::headline($subjectName).'#'.$subjectId;

            if ($mutation->subject_identifier !== null && $mutation->subject_identifier !== '') {
                return $label.' · '.$mutation->subject_identifier;
            }

            return $label;
        }

        return class_basename((string) $mutation->auditable_type).'#'.$mutation->auditable_id;
    }

    public function mutationEventVariant(?string $event): string
    {
        return match ($event) {
            'created' => 'success',
            'updated' => 'info',
            'deleted' => 'danger',
            default => 'default',
        };
    }

    public function mutationEventLabel(?string $event): string
    {
        return match ($event) {
            'created' => __('Created'),
            'updated' => __('Updated'),
            'deleted' => __('Deleted'),
            null, '' => __('Changed'),
            default => Str::headline($event),
        };
    }

    /**
     * @return list<array{field: string, old: string, new: string, sensitive: bool}>
     */
    public function mutationDiffs(AuditMutation $mutation): array
    {
        $oldValues = is_array($mutation->old_values) ? $mutation->old_values : [];
        $newValues = is_array($mutation->new_values) ? $mutation->new_values : [];
        $fields = array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues))));
        sort($fields);

        return array_map(function (string $field) use ($oldValues, $newValues): array {
            $oldValue = $oldValues[$field] ?? null;
            $newValue = $newValues[$field] ?? null;

            return [
                'field' => $field,
                'old' => $this->valueLabel($oldValue),
                'new' => $this->valueLabel($newValue),
                'sensitive' => $this->isProtectedValue($oldValue) || $this->isProtectedValue($newValue),
            ];
        }, $fields);
    }

    /** @return array<string, mixed> */
    public function payload(AuditAction $action): array
    {
        if (is_array($action->payload)) {
            return $action->payload;
        }

        if (is_string($action->payload)) {
            $decoded = json_decode($action->payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function payloadJson(AuditAction $action): string
    {
        $payload = $this->payload($action);

        if ($payload === []) {
            return '';
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function semanticSummary(AuditAction $action, array $payload): array
    {
        $subjectLabel = $this->stringOrNull(data_get($payload, 'subject.label'));
        $contextLabel = $this->semanticContextLabel($payload['context'] ?? null);
        $surface = $this->stringOrNull($payload['surface'] ?? null);
        $uiElement = $this->stringOrNull($payload['ui_element'] ?? null);
        $result = $this->stringOrNull($payload['result'] ?? null) ?? 'recorded';

        return [
            'source' => $this->stringOrNull($payload['source'] ?? null) ?? __('Product'),
            'summary' => $this->stringOrNull($payload['summary'] ?? null) ?? $this->humanizeEvent($action->event),
            'context' => $this->joinContext([$subjectLabel, $contextLabel, $surface, $uiElement]),
            'result' => Str::headline($result),
            'variant' => $this->semanticResultVariant($result),
            'diagnostic' => false,
        ];
    }

    private function semanticContextLabel(mixed $context): ?string
    {
        if (! is_array($context)) {
            return null;
        }

        foreach (['role_names', 'capability_keys', 'fields'] as $key) {
            if (isset($context[$key]) && is_array($context[$key]) && $context[$key] !== []) {
                return implode(', ', array_map('strval', $context[$key]));
            }
        }

        return $this->stringOrNull($context['employee_name'] ?? null);
    }

    /** @param  list<string|null>  $parts */
    private function joinContext(array $parts): ?string
    {
        $context = implode(' · ', array_values(array_filter($parts)));

        return $context !== '' ? $context : null;
    }

    private function httpSummary(AuditAction $action, array $payload): array
    {
        $route = $this->stringOrNull($payload['route'] ?? null);
        $method = $this->stringOrNull($payload['method'] ?? null) ?? 'HTTP';
        $status = $this->intOrNull($payload['status'] ?? null);
        $duration = $this->numberOrNull($payload['duration_ms'] ?? null);
        $path = $this->pathFromUrl($action->url);

        $displayRoute = match (true) {
            $route === 'default-livewire.update' => __('Livewire update'),
            $route !== null => $route,
            $path !== null => $path,
            default => __('Request'),
        };

        $result = $status !== null ? (string) $status : __('Completed');
        if ($duration !== null) {
            $result .= ' · '.number_format($duration, 0).' ms';
        }

        return [
            'source' => __('HTTP'),
            'summary' => strtoupper($method).' '.$displayRoute,
            'context' => $path,
            'result' => $result,
            'variant' => $this->httpStatusVariant($status),
            'diagnostic' => $this->isDiagnosticHttp($action, $payload),
        ];
    }

    private function authSummary(AuditAction $action, array $payload): array
    {
        $email = $this->stringOrNull($payload['email'] ?? null);

        return match ($action->event) {
            'auth.login' => [
                'source' => __('Authentication'),
                'summary' => __('Login'),
                'context' => null,
                'result' => __('Succeeded'),
                'variant' => 'success',
                'diagnostic' => false,
            ],
            'auth.logout' => [
                'source' => __('Authentication'),
                'summary' => __('Logout'),
                'context' => null,
                'result' => __('Completed'),
                'variant' => 'default',
                'diagnostic' => false,
            ],
            'auth.login.failed' => [
                'source' => __('Authentication'),
                'summary' => __('Failed login'),
                'context' => $email,
                'result' => __('Failed'),
                'variant' => 'danger',
                'diagnostic' => false,
            ],
            default => [
                'source' => __('Authentication'),
                'summary' => $this->humanizeEvent($action->event),
                'context' => $email,
                'result' => __('Recorded'),
                'variant' => 'default',
                'diagnostic' => false,
            ],
        };
    }

    private function consoleSummary(array $payload): array
    {
        $command = $this->stringOrNull($payload['command'] ?? null) ?? __('unknown');
        $exitCode = $this->intOrNull($payload['exit_code'] ?? null);

        return [
            'source' => __('Console'),
            'summary' => 'artisan '.$command,
            'context' => null,
            'result' => $exitCode !== null ? __('Exit :code', ['code' => $exitCode]) : __('Completed'),
            'variant' => $exitCode === null || $exitCode === 0 ? 'success' : 'danger',
            'diagnostic' => false,
        ];
    }

    private function queueSummary(AuditAction $action, array $payload): array
    {
        $job = $this->stringOrNull($payload['job'] ?? null) ?? __('Unknown job');
        $queue = $this->stringOrNull($payload['queue'] ?? null);
        $failed = $action->event === 'queue.job.failed';

        return [
            'source' => __('Queue'),
            'summary' => $job,
            'context' => $queue !== null ? __('Queue: :queue', ['queue' => $queue]) : null,
            'result' => $failed ? __('Failed') : __('Processed'),
            'variant' => $failed ? 'danger' : 'success',
            'diagnostic' => false,
        ];
    }

    private function domainSummary(AuditAction $action, array $payload): array
    {
        $domain = $this->stringOrNull($payload['domain'] ?? null);
        $status = $this->stringOrNull($payload['status'] ?? null);
        $actionName = Str::after($action->event, 'domain.');
        $failed = is_string($status) && str_contains(strtolower($status), 'failed');

        return [
            'source' => __('Domain'),
            'summary' => __('Domain :action', ['action' => Str::headline($actionName)]),
            'context' => $domain,
            'result' => $status !== null ? Str::headline($status) : __('Recorded'),
            'variant' => $failed ? 'danger' : 'success',
            'diagnostic' => false,
        ];
    }

    private function isDiagnosticHttp(AuditAction $action, array $payload): bool
    {
        if ($action->event !== 'http.request') {
            return false;
        }

        $route = $this->stringOrNull($payload['route'] ?? null) ?? '';
        $url = (string) ($action->url ?? '');

        return $route === 'default-livewire.update'
            || $route === 'ai.chat.turn.events'
            || $route === 'media.assets.stream'
            || str_contains($url, '/livewire')
            || str_contains($url, '/api/ai/chat/turns/')
            || str_contains($url, '/media/assets/');
    }

    private function httpStatusVariant(?int $status): string
    {
        return match (true) {
            $status === null => 'default',
            $status >= 500 => 'danger',
            $status >= 400 => 'warning',
            $status >= 300 => 'info',
            default => 'success',
        };
    }

    private function semanticResultVariant(string $result): string
    {
        $result = strtolower($result);

        return match (true) {
            str_contains($result, 'fail'), str_contains($result, 'error') => 'danger',
            str_contains($result, 'skip'), str_contains($result, 'noop') => 'warning',
            str_contains($result, 'success'), str_contains($result, 'succeed'), str_contains($result, 'complete') => 'success',
            default => 'default',
        };
    }

    private function humanizeEvent(string $event): string
    {
        return Str::headline(str_replace('.', ' ', $event));
    }

    private function pathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $url;
    }

    private function valueLabel(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('true') : __('false');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '—';
    }

    private function isProtectedValue(mixed $value): bool
    {
        return is_string($value)
            && ($value === '[redacted]' || str_starts_with($value, '[truncated'));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
