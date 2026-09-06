import { describe, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { resolve } from "node:path";
import {
    REQUIRED_CHECK_AUDIT_ISSUE_TITLE,
    missingRequiredChecks,
    readWorkflows,
    requiredCheckAuditIssueBody,
} from "./required-checks";
import { readFileSync } from "node:fs";

const root = resolve(import.meta.dir, "../..");
const snapshot = JSON.parse(readFileSync(resolve(import.meta.dir, "fixtures/protect-main.ruleset.json"), "utf8"));
const cli = resolve(root, "scripts/ci/required-check-audit.ts");

describe("required-check audit reporter", () => {
    test("issue body lists each missing context for operators", () => {
        const body = requiredCheckAuditIssueBody([
            { context: "quality", integration_id: 15368 },
            { context: "SonarCloud Code Analysis", integration_id: 12526 },
        ], "https://example.test/run/1");
        expect(body).toContain("## Missing required checks");
        expect(body).toContain("`quality` (integration_id: 15368)");
        expect(body).toContain("`SonarCloud Code Analysis` (integration_id: 12526)");
        expect(body).toContain("Run: https://example.test/run/1");
        expect(REQUIRED_CHECK_AUDIT_ISSUE_TITLE).toBe("Required check audit failed");
    });

    test("dry-run CLI injects a fake missing name and does not fail the process", () => {
        const result = spawnSync("bun", [cli, "--dry-run"], { encoding: "utf8", cwd: root });
        expect(result.status).toBe(0);
        expect(result.stderr).toContain("fake-required-name-dry-run");
        expect(result.stdout).toContain(REQUIRED_CHECK_AUDIT_ISSUE_TITLE);
        expect(result.stdout).toContain("dry-run: would open or update issue");
        expect(result.stdout).not.toContain("Reported on issue");
    });

    test("live CLI exits zero when the snapshot and workflows agree", () => {
        expect(missingRequiredChecks(snapshot, readWorkflows(root))).toEqual([]);
        const result = spawnSync("bun", [cli], { encoding: "utf8", cwd: root });
        expect(result.status).toBe(0);
        expect(result.stdout).toContain("Required-check producer audit passed.");
    });
});
