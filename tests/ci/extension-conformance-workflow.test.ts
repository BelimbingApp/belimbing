import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/extension-conformance.yml"), "utf8"),
) as any;

test("extension conformance is a workflow_call with path and platform-ref inputs", () => {
    expect(Object.keys(workflow.on)).toEqual(["workflow_call"]);
    expect(workflow.on.workflow_call.inputs["extension-path"].required).toBe(true);
    expect(workflow.on.workflow_call.inputs["platform-ref"].required).toBe(true);
});

test("extension conformance validates mount path and exact platform SHA", () => {
    const validate = workflow.jobs.conformance.steps.find((step: any) => step.name === "Validate inputs");
    expect(validate).toBeDefined();
    expect(validate.run).toContain("^app/Extensions/[A-Z][A-Za-z0-9]*$");
    expect(validate.run).toContain("^[0-9a-f]{40}$");
});

test("extension conformance checks out the platform at platform-ref before migrating", () => {
    const steps = workflow.jobs.conformance.steps;
    const checkout = steps.find((step: any) => step.name === "Checkout platform");
    expect(checkout.uses).toContain("actions/checkout@");
    expect(checkout.with.repository).toBe("BelimbingApp/belimbing");
    expect(checkout.with.ref).toBe("${{ inputs.platform-ref }}");

    const names = steps.map((step: any) => step.name);
    expect(names.indexOf("Checkout platform")).toBeLessThan(
        names.indexOf("Prove composed migrations and schema"),
    );
    expect(names.indexOf("Prove composed migrations and schema")).toBeLessThan(
        names.indexOf("Run Extension conformance"),
    );
});

test("extension conformance runs the shell gate after migrate", () => {
    const run = workflow.jobs.conformance.steps.find(
        (step: any) => step.name === "Run Extension conformance",
    );
    expect(run).toBeDefined();
    expect(run.run).toBe("scripts/ci/extension-conformance.sh '${{ inputs.extension-path }}'");
    const migrate = workflow.jobs.conformance.steps.find(
        (step: any) => step.name === "Prove composed migrations and schema",
    );
    expect(migrate.run).toContain("php artisan migrate");
});
