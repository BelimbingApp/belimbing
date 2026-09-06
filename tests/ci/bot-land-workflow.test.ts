import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
const root = resolve(import.meta.dir, "../..");
test("bot landing requires accepted policy and uses trusted code with the raise token", () => {
    const workflow = Bun.YAML.parse(readFileSync(resolve(root, ".github/workflows/land-bot-maintenance.yml"), "utf8")) as any;
    expect(workflow.on.workflow_run.types).toEqual(["completed"]);
    expect(workflow.jobs.policy.outputs.accepted).toBe("${{ steps.bot_policy.outputs.accepted }}");
    const landing = workflow.jobs["land-bot-maintenance"];
    expect(landing.if).toBe("needs.policy.outputs.accepted == 'true'");
    expect(landing.needs).toBe("policy");
    for (const job of Object.values(workflow.jobs) as any[]) {
        expect(job.steps[0].with.ref).toBe("${{ github.workflow_sha }}");
        expect(job.steps[0].with["persist-credentials"]).toBe(false);
    }
    expect(landing.steps[1].env.GH_TOKEN).toBe("${{ secrets.COVERAGE_BASELINE_RAISE_TOKEN }}");
    expect(landing.steps[1].run).toContain('land-bot-maintenance.py land');
});
test("required-check and merge refusal fixtures exercise the landing entry point", () => {
    const result = Bun.spawnSync(["python3", "tests/ci/test_bot_land.py"], { cwd: root });
    expect(result.exitCode).toBe(0);
});
