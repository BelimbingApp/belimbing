import { expect, test } from "bun:test";
import { mkdtempSync, readFileSync, rmSync, writeFileSync, copyFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { spawnSync } from "node:child_process";

const root = resolve(import.meta.dir, "../..");
const gate = resolve(root, "scripts/ci/phpstan-baseline-gate.sh");
const workflow = Bun.YAML.parse(readFileSync(join(root, ".github/workflows/lint.yml"), "utf8")) as any;

test("quality job runs Larastan then the baseline-count gate", () => {
    const steps = workflow.jobs.quality.steps as Array<{ name?: string; run?: string; "continue-on-error"?: boolean }>;
    const analyse = steps.find((s) => s.name === "Run Larastan on app/Base");
    const countGate = steps.find((s) => s.name === "Refuse PHPStan baseline growth");
    const unmatched = steps.find((s) => s.name === "Report unmatched PHPStan baseline ignores");
    expect(analyse?.run).toContain("vendor/bin/phpstan analyse");
    expect(countGate?.run).toContain("phpstan-baseline-gate.sh");
    expect(steps.indexOf(analyse!)).toBeLessThan(steps.indexOf(countGate!));
    expect(unmatched).toBeDefined();
    expect(unmatched!["continue-on-error"]).toBe(true);
    expect(unmatched!.run).toContain("reportUnmatchedIgnoredErrors: true");
    expect(unmatched!.run).toContain("vendor/bin/phpstan analyse");
    expect(unmatched!.run).toContain("::notice::PHPStan unmatched baseline ignores");
    expect(steps.indexOf(countGate!)).toBeLessThan(steps.indexOf(unmatched!));
});

test("baseline gate accepts the committed count and refuses growth", () => {
    const directory = mkdtempSync(join(tmpdir(), "blb-phpstan-gate-"));
    try {
        copyFileSync(join(root, "phpstan-baseline.neon"), join(directory, "phpstan-baseline.neon"));
        copyFileSync(join(root, "phpstan-baseline.max"), join(directory, "phpstan-baseline.max"));

        const ok = spawnSync("bash", [gate, "phpstan-baseline.neon", "phpstan-baseline.max"], {
            cwd: directory,
            encoding: "utf8",
        });
        expect(ok.status).toBe(0);
        expect(ok.stdout).toContain("phpstan baseline count:");

        // Fixture regression: inflate one ignore count so the sum exceeds max.
        const baseline = readFileSync(join(directory, "phpstan-baseline.neon"), "utf8");
        const mutated = baseline.replace(/\bcount:\s+1\b/, "count: 999");
        expect(mutated).not.toBe(baseline);
        writeFileSync(join(directory, "phpstan-baseline.neon"), mutated);

        const grown = spawnSync("bash", [gate, "phpstan-baseline.neon", "phpstan-baseline.max"], {
            cwd: directory,
            encoding: "utf8",
        });
        expect(grown.status).not.toBe(0);
        expect(grown.stderr).toContain("baseline count grew");
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
