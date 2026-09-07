<?php

namespace App\Core\AI\Services\Orchestration;

/**
 * Parser for the YAML-ish frontmatter block at the head of a `SKILL.md`.
 *
 * Shared by {@see FilesystemSkillPackLoader}, which falls back to derived
 * values when a field is absent, and {@see SkillPackVerifier}, whose whole
 * job is to report that absence. Both must agree on what "declared" means,
 * so the parsing lives in one place.
 */
final class SkillPackFrontmatter
{
    /**
     * Top-level `key: value` pairs of the frontmatter block, if there is one.
     *
     * @return array<string, string>
     */
    public static function fields(string $content): array
    {
        $trimmed = ltrim($content);

        if (! str_starts_with($trimmed, '---')) {
            return [];
        }

        if (! preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', $trimmed, $matches)) {
            return [];
        }

        $fields = [];

        foreach (explode("\n", $matches[1]) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $fields[trim($key)] = trim(trim($value), '"\'');
        }

        return $fields;
    }

    /**
     * Whether the frontmatter declares a non-empty value for a field.
     */
    public static function declares(string $content, string $field): bool
    {
        $value = self::fields($content)[$field] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * Everything after the frontmatter block (the whole file when absent).
     */
    public static function body(string $content): string
    {
        $trimmed = ltrim($content);

        if (! preg_match('/^---\s*\n.*?\n---\s*(?:\n(.*))?$/s', $trimmed, $matches)) {
            return $content;
        }

        return $matches[1] ?? '';
    }
}
