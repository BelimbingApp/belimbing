import { afterEach, describe, expect, test } from "bun:test";
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import {
    COMPOSED_BOOT_FAILED_ISSUE_TITLE,
    closeOpenIssue,
    composedBootFailedIssueBody,
    findOpenIssue,
    upsertIssue,
} from "../../scripts/ci/composed-boot-failure-report";

const root = resolve(import.meta.dir, "../..");
const cli = resolve(root, "scripts/ci/composed-boot-failure-report.ts");
const workflow = Bun.YAML.parse(
    readFileSync(join(root, ".github/workflows/composed-smoke.yml"), "utf8"),
) as any;

const originalPath = process.env.PATH ?? "";
let ghStubHome: string | null = null;

afterEach(() => {
    process.env.PATH = originalPath;
    if (ghStubHome !== null) {
        rmSync(ghStubHome, { recursive: true, force: true });
        ghStubHome = null;
    }
});

/**
 * Put a logging `gh` earlier on PATH so findOpenIssue / upsertIssue /
 * closeOpenIssue exercise the real spawn path without hitting GitHub (#746).
 */
function installGhStub(script: string): string {
    ghStubHome = mkdtempSync(join(tmpdir(), "blb-gh-stub-"));
    const bin = join(ghStubHome, "bin");
    mkdirSync(bin);
    const gh = join(bin, "gh");
    writeFileSync(gh, script, { encoding: "utf8" });
    chmodSync(gh, 0o755);
    process.env.PATH = `${bin}:${originalPath}`;
    return ghStubHome;
}

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

    test("findOpenIssue / upsertIssue / closeOpenIssue pin exact-title and reuse guards", () => {
        const title = "Domain pins stale";
        const home = installGhStub(`#!/usr/bin/env bash
set -euo pipefail
log="$0.log"
printf '%s\\n' "$*" >> "$log"
case "$1 $2" in
  "issue list")
    cat <<'JSON'
[{"number":41,"title":"Domain pins stale — tracking manually, do not close"},{"number":42,"title":"Domain pins stale"}]
JSON
    ;;
  "issue edit")
    echo "https://github.com/BelimbingApp/belimbing/issues/$3"
    ;;
  "issue create")
    echo "https://github.com/BelimbingApp/belimbing/issues/99"
    ;;
  "issue close")
    echo "Closed #$3"
    ;;
  *)
    echo "unexpected: $*" >&2
    exit 2
    ;;
esac
`);
        const log = join(home, "bin", "gh.log");

        expect(findOpenIssue("BelimbingApp/belimbing", title)).toBe(42);

        expect(upsertIssue("BelimbingApp/belimbing", title, "updated body")).toBe(42);
        expect(closeOpenIssue("BelimbingApp/belimbing", title)).toBe(42);

        const calls = readFileSync(log, "utf8");
        expect(calls).toContain("issue edit 42");
        expect(calls).toContain("issue close 42");
        expect(calls).not.toContain("issue create");
        expect(calls).not.toContain("issue edit 41");
        expect(calls).not.toContain("issue close 41");
    });

    test("upsertIssue creates when no exact title is open; closeOpenIssue is a no-op", () => {
        const title = "Domain pins stale";
        const home = installGhStub(`#!/usr/bin/env bash
set -euo pipefail
log="$0.log"
printf '%s\\n' "$*" >> "$log"
case "$1 $2" in
  "issue list")
    echo '[{"number":41,"title":"Domain pins stale — tracking manually, do not close"}]'
    ;;
  "issue create")
    echo "https://github.com/BelimbingApp/belimbing/issues/99"
    ;;
  "issue close"|"issue edit")
    echo "should not close or edit a near miss" >&2
    exit 2
    ;;
  *)
    echo "unexpected: $*" >&2
    exit 2
    ;;
esac
`);
        const log = join(home, "bin", "gh.log");

        expect(findOpenIssue("BelimbingApp/belimbing", title)).toBeNull();
        expect(closeOpenIssue("BelimbingApp/belimbing", title)).toBeNull();
        expect(upsertIssue("BelimbingApp/belimbing", title, "fresh body")).toBe(99);

        const calls = readFileSync(log, "utf8");
        expect(calls).toContain("issue create");
        expect(calls).not.toContain("issue edit");
        expect(calls).not.toContain("issue close");
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
