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
    private const SEAM_MARKERS = [
        'ResolvesWorkforceSubjects',
        'ReadsWorkforceDirectory',
        'WorkforceSubject',
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
        $paramAlternation = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            self::SUBJECT_PARAM_NAMES,
        ));

        // request()->input('employee_id') / $request->query("stableId") /
        // request()->route('employee_entity_id') / ->parameter('subject_id')
        $requestRead = '/(?:\$request|request\(\))\s*(?:->\s*(?:input|query|get|post|integer|string|float|boolean|route)\s*\(\s*[\'"](?:'.$paramAlternation.')[\'"]|\s*->\s*route\s*\(\s*\)\s*->\s*parameter\s*\(\s*[\'"](?:'.$paramAlternation.')[\'"])/';

        $queryUse = '/(?:::\s*(?:query|find|findOrFail|where|whereKey|firstWhere)\s*\(|->\s*(?:where|whereKey|find|findOrFail)\s*\(|DB\s*::\s*(?:table|select)\s*\()/';

        $violations = [];
        $basePath = str_replace('\\', '/', base_path()).'/';

        foreach ((new Finder)->files()->name('*.php')->in($root)->path('Livewire')->sortByName() as $file) {
            $path = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
            $relative = str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path;

            if (isset($allowlist[$relative])) {
                continue;
            }

            $contents = $file->getContents();
            if (preg_match($requestRead, $contents, $match) !== 1) {
                continue;
            }

            if ($this->usesSubjectSeam($contents)) {
                continue;
            }

            if (preg_match($queryUse, $contents) !== 1) {
                continue;
            }

            $violations[] = [
                'path' => $relative,
                'evidence' => trim($match[0]),
            ];
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    public function allowlist(): array
    {
        /** @var array<string, string> $entries */
        $entries = require __DIR__.'/Config/subject_id_request_allowlist.php';

        return $entries;
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
}
