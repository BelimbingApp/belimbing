import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

test("quality validates upstream pins with the workflow token and no failure suppression", () => {
    const workflow = Bun.YAML.parse(readFileSync(resolve(import.meta.dir, "../../.github/workflows/lint.yml"), "utf8")) as any;
    const step = workflow.jobs.quality.steps.find((entry: any) => entry.name === "Validate upstream Domain pins");
    expect(step).toBeDefined();
    expect(step.run).toBe("python3 scripts/ci/validate-domain-pins.py");
    expect(step.env.GITHUB_TOKEN).toBe("${{ secrets.GITHUB_TOKEN }}");
    expect(step.if).toBeUndefined();
    expect(step["continue-on-error"]).toBeUndefined();
});
