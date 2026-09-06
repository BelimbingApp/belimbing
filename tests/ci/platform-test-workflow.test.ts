import { expect, test } from "bun:test";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(readFileSync(join(root, ".github/workflows/tests.yml"), "utf8")) as any;
const suiteSteps = () => workflow.jobs.suites.steps;
const gateSteps = () => workflow.jobs.ci.steps;
const step = (steps: any[], name: string) => {
    const found = steps.find((entry) => entry.name === name);
    expect(found).toBeDefined();
    return found;
};

test("Unit and Feature shards run concurrently, with neither failed lane cancelling the other", () => {
    expect(workflow.jobs.suites).toBeDefined();
    expect(workflow.jobs.suites.needs).toBeUndefined();
    expect(workflow.jobs.suites.strategy).toEqual({
        "fail-fast": false,
        matrix: { suite: ["Unit-a", "Unit-b", "Feature-a", "Feature-b"] },
    });
    const unit = step(suiteSteps(), "Run Tests (Unit shard)");
    expect(unit.if).toBe("startsWith(matrix.suite, 'Unit-')");
    expect(unit.env.MATRIX_SUITE).toContain("matrix.suite");
    expect(unit.env.TIMING_DIR).toBe("timing");
    expect(unit.run).toContain("platform-feature-shards.py --suite=Unit --validate-only");
    expect(unit.run).toContain('coverage-unit-${shard}.xml');
    const feature = step(suiteSteps(), "Run Tests (Feature shard)");
    expect(feature.if).toBe("startsWith(matrix.suite, 'Feature-')");
    expect(feature.env.MATRIX_SUITE).toContain("matrix.suite");
    expect(feature.env.TIMING_DIR).toBe("timing");
    expect(feature.run).toContain("scripts/ci/platform-feature-shards.py");
    expect(feature.run).toContain("scripts/ci/record-pest-timing.sh");
    expect(feature.run).toContain('coverage-feature-${shard}.xml');
    const modules = step(suiteSteps(), "Run Tests (Core, Domains, Extensions)");
    expect(modules.if).toBe("matrix.suite == 'Unit-a'");
    expect(modules.run).toContain("scripts/ci/record-pest-timing.sh Core,Domains,Extensions --");
});

test("all expected reports are uploaded and downloaded by exact artifact name", () => {
    const upload = step(suiteSteps(), "Upload suite coverage");
    expect(upload.with.path).toBe("coverage-*.xml");
    expect(upload.with["if-no-files-found"]).toBe("error");
    expect(upload.with.name).toContain("matrix.suite");
    const timingUpload = step(suiteSteps(), "Upload suite timing");
    expect(timingUpload.with.path).toBe("timing/*.json");
    expect(timingUpload.with["if-no-files-found"]).toBe("error");
    expect(timingUpload.with.name).toContain("platform-timing-");
    for (const suite of ["Unit-a", "Unit-b", "Feature-a", "Feature-b"]) {
        expect(step(gateSteps(), "Download " + suite + " coverage").with.name).toBe("platform-coverage-" + suite);
    }
    const timingDownload = step(gateSteps(), "Download suite timing");
    expect(timingDownload.with.pattern).toBe("platform-timing-*");
    expect(timingDownload.with["merge-multiple"]).toBe(true);
    expect(timingDownload.with.path).toBe("timing");
    expect(step(gateSteps(), "Publish per-run timing summary").run)
        .toContain("scripts/ci/aggregate-pest-timing.py timing");
    expect(step(gateSteps(), "Enforce Pest timing baseline").run)
        .toContain("scripts/ci/pest-timing-ratchet.py timing");
    expect(gateSteps().indexOf(step(gateSteps(), "Enforce Pest timing baseline")))
        .toBe(gateSteps().indexOf(step(gateSteps(), "Publish per-run timing summary")) + 1);
    expect(gateSteps().indexOf(step(gateSteps(), "Publish per-run timing summary")))
        .toBeLessThan(gateSteps().indexOf(step(gateSteps(), "SonarCloud Scan")));
    expect(gateSteps().indexOf(step(gateSteps(), "Require all coverage reports")))
        .toBeLessThan(gateSteps().indexOf(step(gateSteps(), "SonarCloud Scan")));
});


test("PR runs upsert one timing+coverage comment after the coverage check", () => {
    const upsert = step(gateSteps(), "Upsert PR timing and coverage comment");
    expect(upsert.if).toBe("github.event_name == 'pull_request'");
    expect(upsert.env.GH_TOKEN).toContain("github.token");
    expect(upsert.env.PR_NUMBER).toContain("github.event.pull_request.number");
    expect(upsert.run).toContain("scripts/ci/upsert-pr-ci-summary-comment.py");
    expect(upsert.run).toContain("--timing-dir timing");
    expect(upsert.run).toContain("--baseline tests/ci/platform-coverage-baseline.json");
    expect(upsert.run).toContain("coverage-modules.xml");
    expect(gateSteps().indexOf(step(gateSteps(), "Check platform coverage baseline")))
        .toBeLessThan(gateSteps().indexOf(upsert));
    expect(gateSteps().indexOf(upsert))
        .toBeLessThan(gateSteps().indexOf(step(gateSteps(), "SonarCloud Scan")));
});

test("the required ci check always runs and requires successful suites before analysis", () => {
    expect(workflow.jobs.ci.needs).toEqual(["suites"]);
    expect(workflow.jobs.ci.if).toBe("always()");
    const guard = step(gateSteps(), "Require successful suites");
    expect(gateSteps()[0]).toBe(guard);
    expect(guard.env.SUITES_RESULT).toContain("needs.suites.result");
    for (const result of ["success", "failure", "cancelled", "skipped", ""]) {
        const execution = spawnSync("bash", ["-e", "-c", guard.run], {
            env: { ...process.env, SUITES_RESULT: result },
        });
        expect(execution.status === 0).toBe(result === "success");
    }
    expect(workflow.jobs.ci["continue-on-error"]).toBeUndefined();
    expect(workflow.jobs.suites["continue-on-error"]).toBeUndefined();
    for (const entry of [...suiteSteps(), ...gateSteps()]) {
        expect(entry["continue-on-error"]).toBeUndefined();
    }
});

test("the analysis gate refuses every missing or empty report, including module coverage", () => {
    const command = step(gateSteps(), "Require all coverage reports").run;
    const reports = [
        "coverage-unit-a.xml",
        "coverage-unit-b.xml",
        "coverage-feature-a.xml",
        "coverage-feature-b.xml",
        "coverage-modules.xml",
    ];
    const directory = mkdtempSync(join(tmpdir(), "blb-coverage-gate-"));
    try {
        for (const absent of [null, ...reports]) {
            for (const report of reports) {
                rmSync(join(directory, report), { force: true });
                if (report !== absent) writeFileSync(join(directory, report), "<coverage/>");
            }
            expect(spawnSync("bash", ["-e", "-c", command], { cwd: directory }).status === 0).toBe(absent === null);
            if (absent !== null) {
                writeFileSync(join(directory, absent), "");
                expect(spawnSync("bash", ["-e", "-c", command], { cwd: directory }).status).not.toBe(0);
            }
        }
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});

test("all shard reports feed one Sonar scan and downstream dispatch still requires both drivers", () => {
    const scans = Object.values(workflow.jobs).flatMap((job: any) => job.steps ?? [])
        .filter((entry: any) => entry.uses?.startsWith("SonarSource/sonarqube-scan-action@"));
    expect(scans).toHaveLength(1);
    expect((scans[0] as any).with.args).toContain(
        "sonar.php.coverage.reportPaths=coverage-unit-a.xml,coverage-unit-b.xml,coverage-feature-a.xml,coverage-feature-b.xml,coverage-modules.xml",
    );
    expect(workflow.jobs["notify-people-connector"].needs).toEqual(["ci", "postgres-mirror"]);
    expect(workflow.jobs["postgres-mirror"].steps.some((entry: any) => entry.name === "Run native and portable mirror integration tests")).toBeTrue();
});

test("committed Feature shards are disjoint and cover every Feature test file exactly once", () => {
    const validation = spawnSync("python3", ["scripts/ci/platform-feature-shards.py", "--validate-only"], {
        cwd: root,
        encoding: "utf-8",
    });
    expect(validation.status).toBe(0);
    expect(validation.stdout).toContain("ok:");
    expect(validation.stdout).toContain("test files");
    for (const shard of ["a", "b"]) {
        const listed = spawnSync("python3", ["scripts/ci/platform-feature-shards.py", shard], {
            cwd: root,
            encoding: "utf-8",
        });
        expect(listed.status).toBe(0);
        const paths = listed.stdout.trim().split("\n").filter(Boolean);
        expect(paths.length).toBeGreaterThan(0);
        expect(paths.every((entry) => entry.startsWith("tests/Feature/"))).toBe(true);
    }
});
