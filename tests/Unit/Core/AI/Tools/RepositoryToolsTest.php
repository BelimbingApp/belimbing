<?php

use App\Core\AI\DTO\RepositorySurface;
use App\Core\AI\Services\RepositorySurfaceResolver;
use App\Core\AI\Tools\ReadTool;
use App\Core\AI\Tools\SearchTool;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const REPO_TOOL_TEST_DIR = 'tmp/testing/repo-tools';
const REPO_TOOL_DOMAIN_DIR = 'app/Domains/AcmeTest';
const REPO_TOOL_EXTENSION_DIR = 'app/Extensions/AcmeTest';
const REPO_TOOL_CHUNKED_FILE = REPO_TOOL_TEST_DIR.'/chunked.txt';
const REPO_TOOL_WIRE_LOG_RELATIVE_FILE = 'storage'.'/app/ai/wire-logs/run_test.jsonl';
const REPO_TOOL_WIRE_LOG_FIXTURE_FILE = REPO_TOOL_TEST_DIR.'/'.REPO_TOOL_WIRE_LOG_RELATIVE_FILE;

function repoToolTestingSurface(): RepositorySurfaceResolver
{
    return new class extends RepositorySurfaceResolver
    {
        public function resolve(?string $targetSurface = null): RepositorySurface
        {
            return new RepositorySurface(
                target: $targetSurface ?: 'platform',
                rootPath: base_path(REPO_TOOL_TEST_DIR),
                relativeRoot: REPO_TOOL_TEST_DIR,
            );
        }
    };
}

beforeEach(function (): void {
    $this->repoToolNeedle = 'repo_tool_'.str()->random(16);
    File::deleteDirectory(base_path(REPO_TOOL_TEST_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_DOMAIN_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_EXTENSION_DIR));
    File::ensureDirectoryExists(base_path(REPO_TOOL_TEST_DIR));
    File::ensureDirectoryExists(base_path(REPO_TOOL_DOMAIN_DIR));
    File::ensureDirectoryExists(base_path(REPO_TOOL_EXTENSION_DIR));
    File::put(base_path(REPO_TOOL_TEST_DIR.'/sample.txt'), "alpha\n{$this->repoToolNeedle}\n");
    File::put(base_path(REPO_TOOL_DOMAIN_DIR.'/domain.txt'), "domain alpha\n");
    File::put(base_path(REPO_TOOL_EXTENSION_DIR.'/extension.txt'), "extension alpha\n");
});

afterEach(function (): void {
    File::deleteDirectory(base_path(REPO_TOOL_TEST_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_DOMAIN_DIR));
    File::deleteDirectory(base_path(REPO_TOOL_EXTENSION_DIR));
});

it('reads files from the platform surface', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_TEST_DIR.'/sample.txt',
        'target_surface' => 'platform',
    ]);

    expect((string) $result)->toContain('alpha')
        ->and((string) $result)->toContain(REPO_TOOL_TEST_DIR.'/sample.txt');
});

it('returns bounded file chunks with continuation offsets', function (): void {
    File::put(base_path(REPO_TOOL_CHUNKED_FILE), "one\ntwo\nthree\n");

    $first = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_CHUNKED_FILE,
        'target_surface' => 'platform',
        'limit' => 2,
    ]);
    $firstPayload = json_decode((string) $first, true, flags: JSON_THROW_ON_ERROR);

    $second = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_CHUNKED_FILE,
        'target_surface' => 'platform',
        'offset' => $firstPayload['next_offset'],
        'limit' => 2,
    ]);
    $secondPayload = json_decode((string) $second, true, flags: JSON_THROW_ON_ERROR);

    expect($firstPayload['content'])->toBe("one\ntwo\n")
        ->and($firstPayload['has_more'])->toBeTrue()
        ->and($firstPayload['next_offset'])->toBe(2)
        ->and($secondPayload['content'])->toBe("three\n")
        ->and($secondPayload['has_more'])->toBeFalse();
});

it('blocks separately owned paths when target surface is platform', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_EXTENSION_DIR.'/extension.txt',
        'target_surface' => 'platform',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain('separate repository');
});

it('reads files from a domain surface', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => 'domain.txt',
        'target_surface' => 'domain:acme-test',
    ]);

    expect((string) $result)->toContain('domain alpha')
        ->and((string) $result)->toContain('app/Domains/AcmeTest/domain.txt');
});

it('reads files from an extension surface', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => 'extension.txt',
        'target_surface' => 'extension:acme-test',
    ]);

    expect((string) $result)->toContain('extension alpha')
        ->and((string) $result)->toContain('app/Extensions/AcmeTest/extension.txt');
});

it('searches file contents within a surface', function (): void {
    $result = (new SearchTool)->execute([
        'query' => $this->repoToolNeedle,
        'mode' => 'content',
        'target_surface' => 'platform',
        'max_results' => 10,
    ]);

    expect((string) $result)->toContain(REPO_TOOL_TEST_DIR.'/sample.txt')
        ->and((string) $result)->toContain($this->repoToolNeedle);
});

it('excludes AI wire logs from repository search', function (): void {
    File::ensureDirectoryExists(dirname(base_path(REPO_TOOL_WIRE_LOG_FIXTURE_FILE)));
    File::put(base_path(REPO_TOOL_WIRE_LOG_FIXTURE_FILE), $this->repoToolNeedle);

    $result = (new SearchTool(repoToolTestingSurface()))->execute([
        'query' => $this->repoToolNeedle,
        'mode' => 'content',
        'target_surface' => 'platform',
        'max_results' => 10,
    ]);

    expect((string) $result)->toContain('sample.txt')
        ->and((string) $result)->not->toContain(REPO_TOOL_WIRE_LOG_RELATIVE_FILE);
});

it('blocks reading AI wire logs', function (): void {
    $result = (new ReadTool)->execute([
        'target' => 'file',
        'file_path' => REPO_TOOL_WIRE_LOG_RELATIVE_FILE,
        'target_surface' => 'platform',
    ]);

    expect((string) $result)->toContain('Error')
        ->and((string) $result)->toContain(dirname(REPO_TOOL_WIRE_LOG_RELATIVE_FILE).'/');
});
