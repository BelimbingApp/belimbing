<?php
namespace App\Base\AI\Tools\Concerns;

trait ProvidesToolMetadata
{
    public function displayName(): string
    {
        return $this->metadataString('displayName', ucwords(str_replace('_', ' ', $this->name())));
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
        return $this->metadataList('setupRequirements');
    }

    public function testExamples(): array
    {
        return $this->metadataList('testExamples');
    }

    public function healthChecks(): array
    {
        return $this->metadataList('healthChecks');
    }

    public function limits(): array
    {
        return $this->metadataList('limits');
    }

    /**
     * @return array{
     *   displayName?: string,
     *   display_name?: string,
     *   summary?: string,
     *   explanation?: string,
     *   setupRequirements?: list<string>,
     *   setup_requirements?: list<string>,
     *   testExamples?: list<array{label: string, input: array<string, mixed>, runnable?: bool}>,
     *   test_examples?: list<array{label: string, input: array<string, mixed>, runnable?: bool}>,
     *   healthChecks?: list<string>,
     *   health_checks?: list<string>,
     *   limits?: list<string>
     * }
     */
    protected function toolMetadata(): array
    {
        return [];
    }

    /**
     * @return array{
     *   displayName?: string,
     *   display_name?: string,
     *   summary?: string,
     *   explanation?: string,
     *   setupRequirements?: list<string>,
     *   setup_requirements?: list<string>,
     *   testExamples?: list<array{label: string, input: array<string, mixed>, runnable?: bool}>,
     *   test_examples?: list<array{label: string, input: array<string, mixed>, runnable?: bool}>,
     *   healthChecks?: list<string>,
     *   health_checks?: list<string>,
     *   limits?: list<string>
     * }
     */
    protected function metadata(): array
    {
        return [];
    }

    private function metadataString(string $key, string $default = ''): string
    {
        $value = $this->resolvedToolMetadata()[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return list<mixed>
     */
    private function metadataList(string $key): array
    {
        $value = $this->resolvedToolMetadata()[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedToolMetadata(): array
    {
        $metadata = $this->toolMetadata();

        if ($metadata !== []) {
            return $metadata;
        }

        $legacyMetadata = $this->metadata();

        if ($legacyMetadata === []) {
            return [];
        }

        return [
            'displayName' => $legacyMetadata['display_name'] ?? null,
            'summary' => $legacyMetadata['summary'] ?? null,
            'explanation' => $legacyMetadata['explanation'] ?? null,
            'setupRequirements' => $legacyMetadata['setup_requirements'] ?? [],
            'testExamples' => $legacyMetadata['test_examples'] ?? [],
            'healthChecks' => $legacyMetadata['health_checks'] ?? [],
            'limits' => $legacyMetadata['limits'] ?? [],
        ];
    }
}
