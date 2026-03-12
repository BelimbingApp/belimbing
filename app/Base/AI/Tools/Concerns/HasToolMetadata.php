<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools\Concerns;

trait HasToolMetadata
{
    public function displayName(): string
    {
        return $this->metadataString('display_name', ucwords(str_replace('_', ' ', $this->name())));
    }

    public function summary(): string
    {
        return $this->metadataString('summary', $this->description());
    }

    public function explanation(): string
    {
        return $this->metadataString('explanation');
    }

    public function setupRequirements(): array
    {
        return $this->metadataList('setup_requirements');
    }

    public function testExamples(): array
    {
        return $this->metadataList('test_examples');
    }

    public function healthChecks(): array
    {
        return $this->metadataList('health_checks');
    }

    public function limits(): array
    {
        return $this->metadataList('limits');
    }

    /**
     * @return array{
     *     display_name?: string,
     *     summary?: string,
     *     explanation?: string,
     *     setup_requirements?: list<string>,
     *     test_examples?: list<array{label: string, input: array<string, mixed>, runnable?: bool}>,
     *     health_checks?: list<string>,
     *     limits?: list<string>
     * }
     */
    protected function metadata(): array
    {
        return [];
    }

    private function metadataString(string $key, string $default = ''): string
    {
        $value = $this->metadata()[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<mixed>
     */
    private function metadataList(string $key): array
    {
        $value = $this->metadata()[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
