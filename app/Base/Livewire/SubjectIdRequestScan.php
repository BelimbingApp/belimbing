<?php

namespace App\Base\Livewire;

use App\Base\Foundation\ApplicationTopology;
use Symfony\Component\Finder\Finder;

/**
 * Domain Livewire must not treat a raw request or route parameter as a
 * workforce subject id and feed it straight into a query. Equal numbers across
 * identity spaces are not a join key — resolve through the subject seam.
 *
 * Lexical scan only: a hit is a search lead that CI ratchets, not a proof of
 * runtime data flow.
 */
final class SubjectIdRequestScan
{
    /** @var list<string> */
    private const SUBJECT_PARAM_NAMES = [
        'employee_id',
        'employeeId',
        'employee_entity_id',
        'employeeEntityId',
        'employee_subject_id',
        'employeeSubjectId',
        'subject_id',
        'subjectId',
        'stable_id',
        'stableId',
        'workforce_entity_id',
        'workforceEntityId',
        'workforce_subject_id',
        'workforceSubjectId',
    ];

    /** @var list<string> */
    private const REQUEST_READERS = [
        'input',
        'query',
        'get',
        'post',
        'integer',
        'string',
        'float',
        'boolean',
        'route',
    ];

    /** @var list<string> */
    private const SEAM_MARKERS = [
        'ResolvesWorkforceSubjects',
        'ReadsWorkforceDirectory',
        'WorkforceSubject',
    ];

    /** @var list<string> */
    private const QUERY_MARKERS = [
        '::query(',
        '::find(',
        '::findOrFail(',
        '::where(',
        '::whereKey(',
        '::firstWhere(',
        '->where(',
        '->whereKey(',
        '->find(',
        '->findOrFail(',
        'DB::table(',
        'DB::select(',
    ];

    /**
     * @return list<array{path: string, evidence: string}>
     */
    public function violations(?string $domainsRoot = null): array
    {
        $root = $domainsRoot ?? ApplicationTopology::domainsRoot();
        if (! is_dir($root)) {
            return [];
        }

        $allowlist = $this->allowlist();
        $violations = [];
        $basePath = str_replace('\\', '/', base_path()).'/';

        foreach ((new Finder)->files()->name('*.php')->in($root)->path('Livewire')->sortByName() as $file) {
            $path = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
            $relative = str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;

            if (isset($allowlist[$relative])) {
                continue;
            }

            $contents = $file->getContents();
            $evidence = $this->subjectRequestReadEvidence($contents);
            if ($evidence === null || $this->usesSubjectSeam($contents) || ! $this->usesQuery($contents)) {
                continue;
            }

            $violations[] = [
                'path' => $relative,
                'evidence' => $evidence,
            ];
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    public function allowlist(): array
    {
        // Config files that return arrays use require (not require_once) per AGENTS.md.
        return require __DIR__.'/Config/subject_id_request_allowlist.php'; // NOSONAR
    }

    private function subjectRequestReadEvidence(string $contents): ?string
    {
        foreach (self::SUBJECT_PARAM_NAMES as $name) {
            foreach (["'".$name."'", '"'.$name.'"'] as $literal) {
                // Laravel's request() helper also accepts the key as its first
                // argument: request('employee_id') never touches ->input().
                $directHelper = 'request('.$literal;
                if (str_contains($contents, $directHelper)) {
                    return $directHelper;
                }

                foreach (self::REQUEST_READERS as $reader) {
                    $needle = '->'.$reader.'('.$literal;
                    if (str_contains($contents, 'request()') && str_contains($contents, $needle)) {
                        return 'request()'.$needle;
                    }
                    if (str_contains($contents, '$request') && str_contains($contents, $needle)) {
                        return '$request'.$needle;
                    }
                }

                $parameterNeedle = '->parameter('.$literal;
                if (str_contains($contents, '->route()') && str_contains($contents, $parameterNeedle)) {
                    return '->route()'.$parameterNeedle;
                }
            }
        }

        return $this->mountSubjectParamEvidence($contents);
    }

    /**
     * Livewire route/query bindings arrive as mount() parameters. A subject-
     * shaped mount argument that later reaches a query is the same seam miss
     * as reading request()->input() — lexical only.
     */
    private function mountSubjectParamEvidence(string $contents): ?string
    {
        if (! preg_match('/function\s+mount\s*\(([^)]*)\)/', $contents, $matches)) {
            return null;
        }

        $params = $matches[1];
        foreach (self::SUBJECT_PARAM_NAMES as $name) {
            if (preg_match('/\$'.preg_quote($name, '/').'\b/', $params) === 1) {
                return 'mount(...$'.$name.'...)';
            }
        }

        return null;
    }

    private function usesSubjectSeam(string $contents): bool
    {
        foreach (self::SEAM_MARKERS as $marker) {
            if (str_contains($contents, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function usesQuery(string $contents): bool
    {
        foreach (self::QUERY_MARKERS as $marker) {
            if (str_contains($contents, $marker)) {
                return true;
            }
        }

        return false;
    }
}
