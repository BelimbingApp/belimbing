<?php

namespace App\Core\AI\Services\Orchestration;

use App\Core\AI\DTO\Orchestration\SkillPackManifest;

/**
 * Checks a filesystem skill pack declares what its manifest claims.
 *
 * {@see FilesystemSkillPackLoader} is deliberately forgiving: a `SKILL.md`
 * with no frontmatter still yields a manifest, with the name derived from the
 * directory slug and the description cut from the first 200 characters of the
 * body. That makes a malformed pack indistinguishable from a declared one in
 * every field the manifest exposes — same name shape, same `Ready` status.
 * This verifier re-reads the source file so an operator can tell the two
 * apart.
 */
class SkillPackVerifier
{
    /**
     * Below this a `SKILL.md` carries no usable instruction for an agent.
     */
    public const MINIMUM_BODY_LENGTH = 40;

    /**
     * @return list<array{key: string, label: string, passed: bool}>
     */
    public function checks(SkillPackManifest $manifest): array
    {
        $path = $this->sourcePath($manifest);
        $content = $path !== null && is_file($path) ? file_get_contents($path) : false;

        if ($content === false) {
            $content = null;
        }

        $body = $content === null ? '' : trim(SkillPackFrontmatter::body($content));

        return [
            [
                'key' => 'file',
                'label' => 'SKILL.md is readable',
                'passed' => $content !== null,
            ],
            [
                'key' => 'name',
                'label' => 'frontmatter declares a name',
                'passed' => $content !== null && SkillPackFrontmatter::declares($content, 'name'),
            ],
            [
                'key' => 'description',
                'label' => 'frontmatter declares a description',
                'passed' => $content !== null && SkillPackFrontmatter::declares($content, 'description'),
            ],
            [
                'key' => 'body',
                'label' => 'body is at least '.self::MINIMUM_BODY_LENGTH.' characters',
                'passed' => mb_strlen($body) >= self::MINIMUM_BODY_LENGTH,
            ],
        ];
    }

    /**
     * Keys of the checks that failed, in check order.
     *
     * @return list<string>
     */
    public function failedKeys(SkillPackManifest $manifest): array
    {
        $failed = [];

        foreach ($this->checks($manifest) as $check) {
            if (! $check['passed']) {
                $failed[] = $check['key'];
            }
        }

        return $failed;
    }

    /**
     * Absolute path of the `SKILL.md` the manifest was built from.
     */
    private function sourcePath(SkillPackManifest $manifest): ?string
    {
        $path = $manifest->primaryReferencePath();

        if ($path === '') {
            return null;
        }

        // The loader reports paths relative to base_path() when it can, and
        // falls back to the absolute path when the file sits outside it.
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
