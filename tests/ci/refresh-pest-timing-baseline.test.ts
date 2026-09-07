import { expect, test } from "bun:test";
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflowPath = join(root, ".github/workflows/refresh-pest-timing-baseline.yml");
const workflow = () => Bun.YAML.parse(readFileSync(workflowPath, "utf8")) as any;

test("timing refresh selects successful main artifacts and opens a labelled PR", () => {
    const config = workflow();
    expect(Object.keys(config.on)).toEqual(["workflow_dispatch"]);
    expect(config.jobs.refresh.permissions.actions).toBe("read");
    const steps = config.jobs.refresh.steps;
    expect(steps[0].with.ref).toBe("main");
    const select = steps.find((step: any) => step.id === "source");
    expect(select.run).toContain("--branch main --event push --status success");
    const download = steps.find((step: any) => step.uses?.startsWith("actions/download-artifact@"));
    expect(download.with.pattern).toBe("platform-timing-*");
    expect(download.with["merge-multiple"]).toBe(true);
    expect(download.with["run-id"]).toBe("${{ steps.source.outputs.run_id }}");
    const refresh = steps.find((step: any) => step.name === "Refresh baseline");
    expect(refresh.run).toContain("--write-baseline");
    const pr = steps.find((step: any) => step.name === "Open baseline PR");
    expect(config.jobs.refresh.permissions["pull-requests"]).toBeUndefined();
    expect(pr.env.COVERAGE_BASELINE_RAISE_TOKEN).toBe("${{ secrets.COVERAGE_BASELINE_RAISE_TOKEN }}");
    expect(pr.run).toContain("COVERAGE_BASELINE_RAISE_TOKEN is required");
    expect(pr.run).toContain('export GH_TOKEN="$COVERAGE_BASELINE_RAISE_TOKEN"');
    expect(pr.run).toContain("gh pr create");
    expect(pr.run).toContain("--label bot-maintenance");
    expect(pr.run).toContain("tests/ci/pest-timing-baseline.json");
    expect(pr.run).not.toMatch(/push[^\n]*HEAD:main/);
});

test("the actual refresh step retains every downloaded suite and run provenance", () => {
    const fixture = mkdtempSync(join(tmpdir(), "pest-baseline-"));
    try {
        mkdirSync(join(fixture, "timing"));
        mkdirSync(join(fixture, "tests/ci"), { recursive: true });
        const suites = { Unit: 12, "Feature-a": 21, "Feature-b": 34, "Core,Domains,Extensions": 2 };
        for (const [suite, seconds] of Object.entries(suites)) {
            writeFileSync(join(fixture, "timing", `${suite}.json`), JSON.stringify({ suite, wall_seconds: seconds }));
        }
        const step = workflow().jobs.refresh.steps.find((step: any) => step.name === "Refresh baseline");
        const command = step.run.replace("scripts/ci/pest-timing-ratchet.py", join(root, "scripts/ci/pest-timing-ratchet.py"));
        const result = Bun.spawnSync(["bash", "-euo", "pipefail", "-c", command], {
            cwd: fixture, env: { ...process.env, SOURCE_URL: "https://example.test/runs/123" },
        });
        expect(result.exitCode).toBe(0);
        const baseline = JSON.parse(readFileSync(join(fixture, "tests/ci/pest-timing-baseline.json"), "utf8"));
        expect(baseline.suites).toEqual(Object.fromEntries(Object.entries(suites).map(([suite, seconds]) => [suite, { wall_seconds: seconds }])));
        expect(baseline.source).toBe("https://example.test/runs/123");
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
