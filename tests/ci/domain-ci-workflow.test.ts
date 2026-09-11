import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/domain-ci.yml"), "utf8"),
) as any;
const domainRegistry = JSON.parse(
    readFileSync(join(root, "scripts/ci/domain-repos.json"), "utf8"),
) as { domains: string[] };

const sqliteSteps = () => workflow.jobs.sqlite.steps;
const step = (name: string) => {
    const found = sqliteSteps().find((entry: any) => entry.name === name);
    expect(found).toBeDefined();
    return found;
};

test("composed route middleware audit gates SQLite before Domain Pest", () => {
    const name = "Audit composed Domain route middleware";
    const audit = step(name);
    expect(audit.run).toBe("php artisan blb:domain-routes --audit");
    expect(audit.if).toBeUndefined();
    expect(audit["continue-on-error"]).toBeUndefined();
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Pint")).toBeLessThan(names.indexOf(name));
    expect(names.indexOf(name)).toBeLessThan(names.indexOf("Run Tests"));
    expect(workflow.jobs["postgres-mirror"].steps.map((entry: any) => entry.name)).not.toContain(name);
});

test("composed command tenant-scope audit gates SQLite after the route audit and before Domain Pest", () => {
    const name = "Audit composed Domain command tenant scope";
    const audit = step(name);
    expect(audit.run).toContain("php artisan blb:domain-commands --audit --json");
    expect(audit.run).toContain(".expiring[]");
    expect(audit.run).toContain("::warning title=Tenant-scope exemption lapses::");
    expect(audit.run).toContain("exit \"$audit_status\"");
    expect(audit.if).toBeUndefined();
    expect(audit["continue-on-error"]).toBeUndefined();
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Audit composed Domain route middleware")).toBeLessThan(names.indexOf(name));
    expect(names.indexOf(name)).toBeLessThan(names.indexOf("Run Tests"));
    expect(workflow.jobs["postgres-mirror"].steps.map((entry: any) => entry.name)).not.toContain(name);
});

test("composed feature flag ownership runs after Pint and before Domain Pest", () => {
    const name = "Check composed feature flag ownership";
    const scan = step(name);
    expect(scan.run).toBe("vendor/bin/phpstan analyse -c phpstan-feature-flags.neon --memory-limit=2G");
    expect(scan.if).toBeUndefined();
    expect(scan["continue-on-error"]).toBeUndefined();
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Pint")).toBeLessThan(names.indexOf(name));
    expect(names.indexOf(name)).toBeLessThan(names.indexOf("Run Tests"));
    expect(workflow.jobs["postgres-mirror"].steps.map((entry: any) => entry.name)).not.toContain(name);
});

test("domain Sonar scans the DOMAIN_PATH under its own project key", () => {
    const scan = step("SonarCloud Scan");
    const args = scan.with.args as string;
    expect(args).toContain("-Dsonar.projectKey=${{ env.SONAR_PROJECT_KEY }}");
    expect(args).toContain("-Dsonar.sources=${{ env.DOMAIN_PATH }}");
    expect(args).toContain("-Dsonar.tests=${{ env.DOMAIN_PATH }}");
    expect(args).toContain("-Dsonar.coverage.inclusions=${{ env.DOMAIN_PATH }}/**");
    // Nested Domain checkouts are gitignored on the platform; without this,
    // Sonar indexes zero files and coverage never lands on the domain key.
    expect(args).toContain("-Dsonar.scm.exclusions.disabled=true");
});

test("domain CI proves sources exist and strips foreign clover paths before Sonar", () => {
    expect(step("Prove domain sources are present for Sonar").run).toContain("DOMAIN_PATH");
    const filter = step("Restrict coverage clover to the domain under test");
    expect(filter.run).toContain("scripts/ci/filter-domain-coverage-clover.php");
    expect(filter.run).toContain('--domain-path="$DOMAIN_PATH"');
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Restrict coverage clover to the domain under test"))
        .toBeLessThan(names.indexOf("SonarCloud Scan"));
    expect(names.indexOf("Prove domain sources are present for Sonar"))
        .toBeLessThan(names.indexOf("SonarCloud Scan"));
});

test("domain CI scans composed Domains Livewire for raw subject-id request reads", () => {
    const scan = step("Scan Domain Livewire for raw subject-id request reads");
    expect(scan.run).toContain("./vendor/bin/pest");
    expect(scan.run).toContain(
        "keeps the production Domain Livewire tree free of unallowlisted subject-id request reads",
    );
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Pint")).toBeLessThan(
        names.indexOf("Scan Domain Livewire for raw subject-id request reads"),
    );
    expect(names.indexOf("Scan Domain Livewire for raw subject-id request reads")).toBeLessThan(
        names.indexOf("Run Tests"),
    );
    // Lexical only — keep it off the postgres-mirror lane.
    const pgNames = (workflow.jobs["postgres-mirror"].steps as any[]).map((entry: any) => entry.name);
    expect(pgNames).not.toContain("Scan Domain Livewire for raw subject-id request reads");
});

test("domain CI scans composed Domain trees for duplicate Pest helpers", () => {
    const scan = step("Scan composed Domain trees for duplicate Pest helpers");
    expect(scan.run).toContain("./vendor/bin/pest");
    expect(scan.run).toContain(
        "finds no duplicate Domain Pest helpers on the composed checkout",
    );
    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Pint")).toBeLessThan(
        names.indexOf("Scan composed Domain trees for duplicate Pest helpers"),
    );
    expect(names.indexOf("Scan Domain Livewire for raw subject-id request reads")).toBeLessThan(
        names.indexOf("Scan composed Domain trees for duplicate Pest helpers"),
    );
    expect(names.indexOf("Scan composed Domain trees for duplicate Pest helpers")).toBeLessThan(
        names.indexOf("Run Tests"),
    );
    const pgNames = (workflow.jobs["postgres-mirror"].steps as any[]).map((entry: any) => entry.name);
    expect(pgNames).not.toContain("Scan composed Domain trees for duplicate Pest helpers");
});

test("module smoke checks every listed Domain after its suite", () => {
    const smoke = step("Module smoke composition");

    // The descriptor lists ids; the derivation turns each into repo and path.
    expect(domainRegistry.domains.length).toBeGreaterThan(0);
    expect(smoke.run).toContain("php scripts/ci/domain-registry.php --tsv");
    expect(smoke.run).toContain("domain_id repo domain_path");
    // Materialized through the candidate-aware path, never a bare clone that
    // would try only the first owner (#943 review).
    expect(smoke.run).toContain("php scripts/ci/domain-registry.php --materialize");
    expect(smoke.run).not.toContain("git clone");
    // No pin: the smoke composes each Domain at its default branch (#940).
    expect(smoke.run).not.toContain("checkout --quiet --detach");
    expect(smoke.run).toContain('php artisan blb:module-check "$module_id"');

    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Tests")).toBeLessThan(names.indexOf("Module smoke composition"));
});

test("composed Domain ownership runs immediately after module smoke", () => {
    const ownership = step("Check composed Domain ownership");

    expect(ownership.run).toBe("php artisan blb:module-ownership");
    expect(ownership.if).toBeUndefined();
    expect(ownership["continue-on-error"]).toBeUndefined();

    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Check composed Domain ownership"))
        .toBe(names.indexOf("Module smoke composition") + 1);
    expect(workflow.jobs["postgres-mirror"].steps.map((entry: any) => entry.name))
        .not.toContain("Check composed Domain ownership");
});

test("both Domain test lanes raise the coverage memory limit before Pest runs", () => {
    for (const [job, runStep] of [["sqlite", "Run Tests"], ["postgres-mirror", "Run Tests on PostgreSQL"]] as const) {
        const names = workflow.jobs[job].steps.map((entry: any) => entry.name);
        const raise = names.indexOf("Raise the memory limit for coverage");
        expect(raise).toBeGreaterThan(-1);
        expect(raise).toBeLessThan(names.indexOf(runStep));
        const run = workflow.jobs[job].steps[raise].run as string;
        expect(run).toContain('value="2G"');
        expect(run).toContain("phpunit.xml");
    }
});

test("both jobs compose cross-domain dependencies without unknown flags", () => {
    const name = "Compose exact cross-domain dependencies";
    for (const jobName of ["sqlite", "postgres-mirror"] as const) {
        const steps = workflow.jobs[jobName].steps as any[];
        const found = steps.find((entry: any) => entry.name === name);
        expect(found).toBeDefined();
        expect(found.run).toContain('php scripts/ci/compose-domain.php --domain-path="$DOMAIN_PATH"');
        expect(found.run).not.toContain("--exact");
        expect(found.run).toContain("> extra-repos.tsv");
    }
});
