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

test("composed-smoke materializes every Domain the descriptor lists, at its default branch", () => {
    const compose = workflow.jobs["composed-smoke"].steps.find(
        (step: any) => step.name === "Compose the Domains",
    );
    expect(compose).toBeDefined();
    // Enumerated through the derivation, never a hand-written subset (#940).
    expect(compose.run).toContain("scripts/ci/domain-registry.php --tsv");
    expect(compose.run).not.toContain("for id in people people-connector");
    // No pin: checking out a fixed SHA is what this workflow stopped doing.
    expect(compose.run).not.toContain("checkout --detach");
    const hold = workflow.jobs["composed-smoke"].steps.find(
        (step: any) => step.name === "Boot the composed application",
    );
    expect(hold.run).toContain("php scripts/ci/composed-smoke.php");
    expect(hold.run).toContain("GITHUB_OUTPUT");
    expect(hold.id).toBe("boot");
});

test("composed-smoke audits Domain command tenant scope after a successful boot (#912)", () => {
    const steps = workflow.jobs["composed-smoke"].steps as any[];
    const audit = steps.find(
        (step: any) => step.name === "Audit composed Domain command tenant scope",
    );
    expect(audit).toBeDefined();
    expect(audit.run).toBe("php artisan blb:domain-commands --audit");
    expect(audit.if).toContain("steps.boot.outputs.exit_code == '0'");
    expect(audit["continue-on-error"]).toBeUndefined();

    const bootIndex = steps.findIndex(
        (step: any) => step.name === "Boot the composed application",
    );
    const refuseIndex = steps.findIndex(
        (step: any) => step.name === "Fail when the composed boot refused",
    );
    const auditIndex = steps.findIndex(
        (step: any) => step.name === "Audit composed Domain command tenant scope",
    );
    expect(auditIndex).toBeGreaterThan(bootIndex);
    expect(auditIndex).toBeGreaterThan(refuseIndex);

    const yaml = readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8");
    expect(yaml).toContain("unconverted Domain\n      # command fails here on");
    expect(yaml).toContain("isAllowlisted()");
    expect(yaml).toContain("this step is not");
    expect(yaml).toContain("proof that the allowlisted set is correct or empty");
});

test("composed-smoke audits Domain route middleware after a successful boot (#917)", () => {
    const steps = workflow.jobs["composed-smoke"].steps as any[];
    const audit = steps.find(
        (step: any) => step.name === "Audit composed Domain route middleware",
    );
    expect(audit).toBeDefined();
    expect(audit.run).toBe("php artisan blb:domain-routes --audit");
    expect(audit.if).toContain("steps.boot.outputs.exit_code == '0'");
    expect(audit["continue-on-error"]).toBeUndefined();

    const bootIndex = steps.findIndex(
        (step: any) => step.name === "Boot the composed application",
    );
    const refuseIndex = steps.findIndex(
        (step: any) => step.name === "Fail when the composed boot refused",
    );
    const commandsIndex = steps.findIndex(
        (step: any) => step.name === "Audit composed Domain command tenant scope",
    );
    const auditIndex = steps.findIndex(
        (step: any) => step.name === "Audit composed Domain route middleware",
    );
    expect(auditIndex).toBeGreaterThan(bootIndex);
    expect(auditIndex).toBeGreaterThan(refuseIndex);
    expect(auditIndex).toBeGreaterThan(commandsIndex);

    const yaml = readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8");
    expect(yaml).toContain("A bare Domain route\n      # fails here on");
    expect(yaml).toContain("middleware_audit.allowlist");
    expect(yaml).toContain("this step is not proof that the allowlisted");
});

test("composed-smoke checks Domain ownership after a successful boot (#917)", () => {
    const steps = workflow.jobs["composed-smoke"].steps as any[];
    const ownership = steps.find(
        (step: any) => step.name === "Check composed Domain ownership",
    );
    expect(ownership).toBeDefined();
    expect(ownership.run).toBe("php artisan blb:module-ownership");
    expect(ownership.if).toContain("steps.boot.outputs.exit_code == '0'");
    expect(ownership["continue-on-error"]).toBeUndefined();

    const routesIndex = steps.findIndex(
        (step: any) => step.name === "Audit composed Domain route middleware",
    );
    const ownershipIndex = steps.findIndex(
        (step: any) => step.name === "Check composed Domain ownership",
    );
    expect(ownershipIndex).toBeGreaterThan(routesIndex);

    const yaml = readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8");
    expect(yaml).toContain("Domain pair cannot collide");
    expect(yaml).toContain("blb:module-check is intentionally not composed here");
});
