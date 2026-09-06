import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

test("quality gates platform action debt without opting into strict names", () => {
    const workflow = Bun.YAML.parse(readFileSync(resolve(import.meta.dir, "../../.github/workflows/lint.yml"), "utf8")) as any;
    const steps = workflow.jobs.quality.steps;
    const check = steps.find((entry: any) => entry.name === "Check platform Livewire action baseline");
    expect(check).toBeDefined();
    expect(check.run).toBe("php artisan blb:livewire-actions --check-baseline=tests/ci/livewire-actions-baselines/platform.json");
    expect(check.env.APP_ENV).toBe("testing");
    expect(check.if).toBeUndefined();
    expect(check["continue-on-error"]).toBeUndefined();
    expect(steps.indexOf(check)).toBeGreaterThan(steps.findIndex((entry: any) => entry.name === "Install Dependencies"));
});
