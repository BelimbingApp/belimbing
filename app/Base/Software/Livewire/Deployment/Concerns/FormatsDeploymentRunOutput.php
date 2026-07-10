<?php

namespace App\Base\Software\Livewire\Deployment\Concerns;

trait FormatsDeploymentRunOutput
{
    public function runLineClass(string $line): string
    {
        return match (true) {
            $this->isErrorLine($line) => 'text-status-danger',
            $this->isWarningLine($line) => 'text-status-warning',
            str_starts_with($line, 'Update complete.') || str_starts_with($line, 'Verified:') => 'text-status-success',
            default => '',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'error' => (string) __('Needs action'),
            'warning' => (string) __('Warnings'),
            'pending' => (string) __('Reload pending'),
            'success' => (string) __('Complete'),
            default => (string) __('No run yet'),
        };
    }

    private function statusVariant(string $status): string
    {
        return match ($status) {
            'error' => 'danger',
            'warning' => 'warning',
            'pending' => 'warning',
            'success' => 'success',
            default => 'default',
        };
    }

    private function isErrorLine(string $line): bool
    {
        $lower = strtolower($line);

        return str_starts_with($line, 'FAILED:')
            || str_contains($lower, 'finished with errors')
            || str_contains($lower, ' install failed:')
            || str_contains($lower, ' build failed:')
            || str_contains($lower, ' refresh failed:');
    }

    private function isWarningLine(string $line): bool
    {
        return str_starts_with($line, 'Warning:')
            || str_starts_with($line, 'Still behind:')
            || str_starts_with($line, 'Could not verify')
            || str_contains(strtolower($line), 'finished with warnings');
    }

    private function isPendingLine(string $line): bool
    {
        $lower = strtolower($line);

        return str_contains($lower, 'runtime reload scheduled')
            || str_contains($lower, 'runtime reload is already scheduled');
    }
}
