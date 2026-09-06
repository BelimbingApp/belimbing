import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8"),
) as any;

test("composed-smoke runs on every PR to main without a paths filter", () => {
    expect(workflow.on.pull_request.branches).toEqual(["main"]);
    expect(workflow.on.pull_request.paths).toBeUndefined();
    expect(workflow.on.push.branches).toEqual(["main"]);
    expect(workflow.on.workflow_dispatch).toBeDefined();
});

test("composed-smoke materializes People and PeopleConnector from the descriptor", () => {
    const compose = workflow.jobs["composed-smoke"].steps.find(
        (step: any) => step.name === "Compose the pinned Domains",
    );
    expect(compose).toBeDefined();
    expect(compose.run).toContain("scripts/ci/domain-repos.json");
    expect(compose.run).toContain("people people-connector");
    const hold = workflow.jobs["composed-smoke"].steps.find(
        (step: any) => step.name === "Boot the composed application and hold it to the surface",
    );
    expect(hold.run).toBe("php scripts/ci/composed-smoke.php");
});
