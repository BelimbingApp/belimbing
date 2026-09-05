import { describe, expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { auditRequiredChecks, readWorkflows } from "./required-checks";

const root = resolve(import.meta.dir, "../..");
const snapshot = JSON.parse(readFileSync(resolve(import.meta.dir, "fixtures/protect-main.ruleset.json"), "utf8"));
const workflows = readWorkflows(root);
const clone = <T>(value: T): T => structuredClone(value);

describe("required-check producer contract", () => {
    test("every required snapshot context has an actual PR producer", () => {
        expect(auditRequiredChecks(snapshot, workflows).length).toBeGreaterThan(0);
    });

    test("a fake required check fails even when a step has that display name", () => {
        const changed = clone(snapshot);
        changed.rules.find((rule: any) => rule.type === "required_status_checks")
            .parameters.required_status_checks.push({ context: "nonexistent-required-check", integration_id: 15368 });
        const jobs = clone(workflows);
        jobs["tests.yml"].jobs.ci.steps.push({ name: "nonexistent-required-check", run: "true" });
        expect(() => auditRequiredChecks(changed, jobs)).toThrow("nonexistent-required-check");
    });

    test("renaming a job without updating the ruleset fails", () => {
        const changed = clone(workflows);
        changed["lint.yml"].jobs.quality.name = "renamed-quality";
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("quality");
    });

    test("a reusable-only workflow cannot stand in for a PR producer", () => {
        const changed = clone(workflows);
        changed["security.yml"].on = { workflow_call: {} };
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("Secret scan");
    });

    test("removing the Sonar scan cannot be hidden by a job with its label", () => {
        const changed = clone(workflows);
        changed["tests.yml"].jobs.ci.steps = changed["tests.yml"].jobs.ci.steps
            .filter((step: any) => !step.uses?.startsWith("SonarSource/sonarqube-scan-action@"));
        changed["tests.yml"].jobs.fakeSonar = { name: "SonarCloud Code Analysis", "runs-on": "ubuntu-latest", steps: [] };
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("SonarCloud Code Analysis");
    });

    test("an explicitly disabled scan does not produce the external check", () => {
        const changed = clone(workflows);
        changed["tests.yml"].jobs.ci.steps.find((step: any) =>
            step.uses?.startsWith("SonarSource/sonarqube-scan-action@")).if = false;
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("SonarCloud Code Analysis");
    });

    test("matrix or expression names cannot falsely satisfy a static required context", () => {
        const changed = clone(workflows);
        changed["lint.yml"].jobs.quality.strategy = { matrix: { driver: ["sqlite", "pgsql"] } };
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("quality");
        delete changed["lint.yml"].jobs.quality.strategy;
        changed["lint.yml"].jobs.quality.name = "$" + "{{ matrix.name }}";
        expect(() => auditRequiredChecks(snapshot, changed)).toThrow("quality");
    });

    test("an empty or inactive policy snapshot fails closed", () => {
        expect(() => auditRequiredChecks({ ...snapshot, rules: [] }, workflows)).toThrow("no required checks");
        expect(() => auditRequiredChecks({ ...snapshot, enforcement: "disabled" }, workflows)).toThrow("active branch ruleset");
    });
});
