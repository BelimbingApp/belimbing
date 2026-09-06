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

test("Unit and Feature run concurrently, with neither failed lane cancelling the other", () => {
    expect(workflow.jobs.suites).toBeDefined();
    expect(workflow.jobs.suites.needs).toBeUndefined();
    expect(workflow.jobs.suites.strategy).toEqual({
        "fail-fast": false, matrix: { suite: ["Unit", "Feature"] },
    });
    for (const suite of ["Unit", "Feature"]) {
        const command = step(suiteSteps(), "Run Tests (" + suite + ")");
        expect(command.if).toBe("matrix.suite == '" + suite + "'");
        expect(command.env.MATRIX_SUITE).toContain("matrix.suite");
        expect(command.env.TIMING_DIR).toBe("timing");
        expect(command.run).toContain("scripts/ci/record-pest-timing.sh " + suite + " --");
        expect(command.run).toContain("--testsuite=" + suite + " --coverage-clover=coverage-" + suite.toLowerCase() + ".xml");
    }
    const modules = step(suiteSteps(), "Run Tests (Core, Domains, Extensions)");
    expect(modules.if).toBe("matrix.suite == 'Unit'");
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
    for (const suite of ["Unit", "Feature"]) {
        expect(step(gateSteps(), "Download " + suite + " coverage").with.name).toBe("platform-coverage-" + suite);
    }
    const timingDownload = step(gateSteps(), "Download suite timing");
    expect(timingDownload.with.pattern).toBe("platform-timing-*");
    expect(timingDownload.with["merge-multiple"]).toBe(true);
    expect(timingDownload.with.path).toBe("timing");
    expect(step(gateSteps(), "Publish per-run timing summary").run)
        .toContain("scripts/ci/aggregate-pest-timing.py timing");
    expect(gateSteps().indexOf(step(gateSteps(), "Publish per-run timing summary")))
        .toBeLessThan(gateSteps().indexOf(step(gateSteps(), "SonarCloud Scan")));
    expect(gateSteps().indexOf(step(gateSteps(), "Require all coverage reports")))
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
    const reports = ["coverage-unit.xml", "coverage-feature.xml", "coverage-modules.xml"];
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

test("all three reports feed one Sonar scan and downstream dispatch still requires both drivers", () => {
    const scans = Object.values(workflow.jobs).flatMap((job: any) => job.steps ?? [])
        .filter((entry: any) => entry.uses?.startsWith("SonarSource/sonarqube-scan-action@"));
    expect(scans).toHaveLength(1);
    expect((scans[0] as any).with.args).toContain("sonar.php.coverage.reportPaths=coverage-unit.xml,coverage-feature.xml,coverage-modules.xml");
    expect(workflow.jobs["notify-people-connector"].needs).toEqual(["ci", "postgres-mirror"]);
    expect(workflow.jobs["postgres-mirror"].steps.some((entry: any) => entry.name === "Run native and portable mirror integration tests")).toBeTrue();
});
