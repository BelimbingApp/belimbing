import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/refresh-feature-shard-timings.yml"), "utf8"),
) as any;

const steps = () => workflow.jobs.refresh.steps as any[];
const step = (name: string) => {
    const found = steps().find((entry) => entry.name === name);
    expect(found).toBeDefined();
    return found;
};

test("refresh-feature-shard-timings is dispatch-only and never pushes to main", () => {
    expect(workflow.on).toEqual({
        workflow_dispatch: {
            inputs: {
                run_id: {
                    description: "Optional tests workflow run id on main (default latest success)",
                    required: false,
                    type: "string",
                },
            },
        },
    });
    expect(workflow.on.push).toBeUndefined();
    expect(workflow.on.pull_request).toBeUndefined();

    const refresh = step("Refresh timing summary and rebalance shards");
    expect(refresh.run).toContain("scripts/ci/platform-feature-shard-timings.py");
    expect(refresh.run).toContain("--timing-dir timing");
    expect(refresh.run).toContain("platform-feature-shards.py --write-balanced");

    const openPr = step("Open PR with refreshed timings");
    expect(openPr.run).toContain("gh pr create");
    expect(openPr.run).toContain("ci/refresh-feature-shard-timings");
    expect(openPr.run).not.toMatch(/git push[^\n]*\smain\b/);
    expect(openPr.run).toContain("push --force-with-lease origin");
    expect(openPr.run).toContain("HEAD:refs/heads/");
    expect(openPr.run).toContain("Protect Main refuses direct pushes");
});
