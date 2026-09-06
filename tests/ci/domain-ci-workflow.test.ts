import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/domain-ci.yml"), "utf8"),
) as any;
const domainRegistry = JSON.parse(
    readFileSync(join(root, "scripts/ci/domain-repos.json"), "utf8"),
) as { domains: Record<string, unknown> };

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

test("module smoke checks every pinned Domain after its suite", () => {
    const smoke = step("Module smoke composition");

    expect(Object.keys(domainRegistry.domains).length).toBeGreaterThan(0);
    expect(smoke.run).toContain(".domains | to_entries[]");
    expect(smoke.run).toContain("domain_id repo domain_path ref");
    expect(smoke.run).toContain('git clone --quiet --filter=blob:none --no-checkout');
    expect(smoke.run).toContain('git -C "$domain_path" checkout --quiet --detach "$ref"');
    expect(smoke.run).toContain('php artisan blb:module-check "$module_id"');

    const names = sqliteSteps().map((entry: any) => entry.name);
    expect(names.indexOf("Run Tests")).toBeLessThan(names.indexOf("Module smoke composition"));
});
