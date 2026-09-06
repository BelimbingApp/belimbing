import { describe, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import {
    COMPOSED_BOOT_FAILED_ISSUE_TITLE,
    composedBootFailedIssueBody,
} from "../../scripts/ci/composed-boot-failure-report";

const root = resolve(import.meta.dir, "../..");
const cli = resolve(root, "scripts/ci/composed-boot-failure-report.ts");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8"),
) as any;

describe("composed boot failure reporter", () => {
    test("issue body carries the refusal and run URL", () => {
        const body = composedBootFailedIssueBody(
            "composed-smoke: route collision",
            "https://example.test/run/9",
        );
        expect(body).toContain("## Refusal");
        expect(body).toContain("composed-smoke: route collision");
        expect(body).toContain("Run: https://example.test/run/9");
        expect(COMPOSED_BOOT_FAILED_ISSUE_TITLE).toBe("Composed boot failed");
    });

    test("dry-run CLI prints a synthetic refusal and does not call gh", () => {
        const result = spawnSync("bun", [cli, "--dry-run"], { encoding: "utf8", cwd: root });
        expect(result.status).toBe(0);
        expect(result.stdout).toContain("composed-smoke: dry-run refusal (synthetic)");
        expect(result.stdout).toContain(COMPOSED_BOOT_FAILED_ISSUE_TITLE);
        expect(result.stdout).toContain("dry-run: would open or update issue");
        expect(result.stdout).not.toContain("Reported on issue");
    });
});

describe("composed-smoke nightly schedule", () => {
    test("runs daily and exposes a dry-run dispatch input", () => {
        expect(workflow.on.schedule).toEqual([{ cron: "41 5 * * *" }]);
        expect(workflow.on.workflow_dispatch.inputs.dry_run.type).toBe("boolean");
        expect(workflow.jobs["composed-smoke"].permissions.issues).toBe("write");
    });

    test("reports refusal on schedule and keeps the pin-advance PR path", () => {
        expect(workflow.on.pull_request.branches).toEqual(["main"]);
        expect(workflow.on.pull_request.paths).toBeUndefined();
        const report = workflow.jobs["composed-smoke"].steps.find(
            (step: any) => step.name === "Report composed boot refusal",
        );
        expect(report).toBeDefined();
        expect(report.run).toContain("composed-boot-failure-report.ts");
        expect(report.run).toContain("--dry-run");
        const boot = workflow.jobs["composed-smoke"].steps.find(
            (step: any) => step.name === "Boot the composed application and hold it to the surface",
        );
        expect(boot.run).toContain("php scripts/ci/composed-smoke.php");
        expect(boot.run).toContain("GITHUB_OUTPUT");
    });
});
