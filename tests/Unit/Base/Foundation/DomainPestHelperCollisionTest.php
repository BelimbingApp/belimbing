<?php

/**
 * Tenant isolation: not applicable — filesystem text scan of Domain trees only.
 *
 * Pest declares file-level helpers as global functions. Two Domain packages that
 * choose the same top-level name fatal when both trees load in one process
 * (belimbing#384 / transferred #891). Platform tests.yml sees an empty Domains
 * tree; domain-ci.yml runs the composed-checkout assertion per lane (intra-
 * Domain). Cross-Domain coverage belongs on composed-smoke once every pin is
 * collision-free (blocked on blb-people#386 / cutoverFixture rename).
 */

use App\Base\Foundation\ApplicationTopology;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Column-0 `function name` declarations under each Domain root, keyed by name.
 * Class methods are indented and therefore ignored — matching the grep pipeline
 * that surfaced #384.
 *
 * @return array<string, list<string>> map of function name => relative paths
 */
function domainPestHelperDeclarationsByName(string $domainsRoot): array
{
    if (! is_dir($domainsRoot)) {
        return [];
    }

    $byName = [];
    $domainsRoot = rtrim(str_replace('\\', '/', $domainsRoot), '/');
    $prefix = $domainsRoot.'/';

    foreach (File::directories($domainsRoot) as $domainAbsolute) {
        $domainAbsolute = str_replace('\\', '/', $domainAbsolute);
        $finder = File::allFiles($domainAbsolute);

        foreach ($finder as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = str_starts_with($absolute, $prefix)
                ? substr($absolute, strlen($prefix))
                : $absolute;

            $handle = fopen($absolute, 'r');
            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (preg_match('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $match) !== 1) {
                        continue;
                    }

                    $byName[$match[1]][] = $relative;
                }
            } finally {
                fclose($handle);
            }
        }
    }

    ksort($byName);

    return $byName;
}

/**
 * @param  array<string, list<string>>  $byName
 * @return array<string, list<string>>
 */
function domainPestHelperCollisions(array $byName): array
{
    $collisions = [];

    foreach ($byName as $name => $paths) {
        $unique = array_values(array_unique($paths));
        if (count($unique) < 2) {
            continue;
        }

        sort($unique);
        $collisions[$name] = $unique;
    }

    ksort($collisions);

    return $collisions;
}

/**
 * @return list<string>
 */
function domainPestHelperCollisionMessages(array $collisions): array
{
    $messages = [];

    foreach ($collisions as $name => $paths) {
        $messages[] = "duplicate Pest helper function {$name}() in: ".implode(', ', $paths);
    }

    return $messages;
}

it('fails when the same top-level function is declared in two Domain trees, naming both paths', function (): void {
    $domainsRoot = sys_get_temp_dir().'/blb-pest-helper-collision-'.bin2hex(random_bytes(8));
    $a = $domainsRoot.'/ZzPestHelperA/Probe';
    $b = $domainsRoot.'/ZzPestHelperB/Probe';
    $fileA = $a.'/dup.php';
    $fileB = $b.'/dup.php';

    File::ensureDirectoryExists($a);
    File::ensureDirectoryExists($b);
    File::put($fileA, "<?php\n\nfunction zzSharedPestHelperCollisionProbe(): void\n{\n}\n");
    File::put($fileB, "<?php\n\nfunction zzSharedPestHelperCollisionProbe(): void\n{\n}\n");

    try {
        $collisions = domainPestHelperCollisions(domainPestHelperDeclarationsByName($domainsRoot));
        $messages = domainPestHelperCollisionMessages($collisions);

        expect($collisions)->toHaveKey('zzSharedPestHelperCollisionProbe')
            ->and($collisions['zzSharedPestHelperCollisionProbe'])->toContain(
                'ZzPestHelperA/Probe/dup.php',
                'ZzPestHelperB/Probe/dup.php',
            )
            ->and(implode("\n", $messages))
            ->toContain('ZzPestHelperA/Probe/dup.php')
            ->toContain('ZzPestHelperB/Probe/dup.php')
            ->toContain('zzSharedPestHelperCollisionProbe');
    } finally {
        File::deleteDirectory($domainsRoot);
    }
});

it('passes when a top-level function name appears in only one Domain file', function (): void {
    $domainsRoot = sys_get_temp_dir().'/blb-pest-helper-collision-'.bin2hex(random_bytes(8));
    $a = $domainsRoot.'/ZzPestHelperSolo/Probe';
    $fileA = $a.'/solo.php';

    File::ensureDirectoryExists($a);
    File::put($fileA, "<?php\n\nfunction zzSoloPestHelperCollisionProbe(): void\n{\n}\n");

    try {
        $collisions = domainPestHelperCollisions(domainPestHelperDeclarationsByName($domainsRoot));

        expect($collisions)->not->toHaveKey('zzSoloPestHelperCollisionProbe');
    } finally {
        File::deleteDirectory($domainsRoot);
    }
});

it('ignores identically named class methods — only column-0 function declarations count', function (): void {
    $domainsRoot = sys_get_temp_dir().'/blb-pest-helper-collision-'.bin2hex(random_bytes(8));
    $a = $domainsRoot.'/ZzPestHelperMethodA/Probe';
    $b = $domainsRoot.'/ZzPestHelperMethodB/Probe';

    File::ensureDirectoryExists($a);
    File::ensureDirectoryExists($b);
    File::put($a.'/Klass.php', "<?php\n\nclass ZzPestHelperMethodA\n{\n    public function zzMethodNameSharedAcrossDomains(): void\n    {\n    }\n}\n");
    File::put($b.'/Klass.php', "<?php\n\nclass ZzPestHelperMethodB\n{\n    public function zzMethodNameSharedAcrossDomains(): void\n    {\n    }\n}\n");

    try {
        $collisions = domainPestHelperCollisions(domainPestHelperDeclarationsByName($domainsRoot));

        expect($collisions)->not->toHaveKey('zzMethodNameSharedAcrossDomains');
    } finally {
        File::deleteDirectory($domainsRoot);
    }
});

it('fails when two files in the same Domain declare the same top-level function', function (): void {
    $domainsRoot = sys_get_temp_dir().'/blb-pest-helper-collision-'.bin2hex(random_bytes(8));
    $mod = $domainsRoot.'/ZzPestHelperIntra/Probe';

    File::ensureDirectoryExists($mod);
    File::put($mod.'/one.php', "<?php\n\nfunction zzIntraDomainPestHelperCollision(): void\n{\n}\n");
    File::put($mod.'/two.php', "<?php\n\nfunction zzIntraDomainPestHelperCollision(): void\n{\n}\n");

    try {
        $collisions = domainPestHelperCollisions(domainPestHelperDeclarationsByName($domainsRoot));

        expect($collisions)->toHaveKey('zzIntraDomainPestHelperCollision')
            ->and($collisions['zzIntraDomainPestHelperCollision'])->toContain(
                'ZzPestHelperIntra/Probe/one.php',
                'ZzPestHelperIntra/Probe/two.php',
            );
    } finally {
        File::deleteDirectory($domainsRoot);
    }
});

it('finds no duplicate Domain Pest helpers on the composed checkout', function (): void {
    $domainsRoot = base_path(ApplicationTopology::DOMAINS);
    $collisions = domainPestHelperCollisions(domainPestHelperDeclarationsByName($domainsRoot));
    $messages = domainPestHelperCollisionMessages($collisions);

    expect($messages)->toBeEmpty(
        "duplicate Domain Pest helpers:\n".implode("\n", $messages),
    );
});
