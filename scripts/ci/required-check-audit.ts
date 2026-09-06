#!/usr/bin/env bun
/**
 * Scheduled / dispatch runner for the Protect Main required-check producer audit.
 *
 * Default: audit the checked-in ruleset snapshot against live workflow YAML.
 * On failure: open or update the single issue titled "Required check audit failed".
 *
 * --dry-run: inject a fake missing required name, print the issue body that would
 * be posted, and exit 0 without calling the GitHub API.
 */
import { spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
    REQUIRED_CHECK_AUDIT_ISSUE_TITLE,
    formatMissingRequiredChecks,
    missingRequiredChecks,
    readWorkflows,
    requiredCheckAuditIssueBody,
    type RequiredCheck,
} from "../../tests/ci/required-checks";

const root = resolve(import.meta.dir, "../..");
const snapshotPath = resolve(root, "tests/ci/fixtures/protect-main.ruleset.json");
const FAKE_MISSING = "fake-required-name-dry-run";

function parseArgs(argv: string[]): { dryRun: boolean; repo: string; runUrl: string } {
    let dryRun = false;
    let repo = process.env.GITHUB_REPOSITORY ?? "BelimbingApp/belimbing";
    let runUrl = process.env.GITHUB_RUN_URL ?? "";
    for (let index = 0; index < argv.length; index++) {
        const arg = argv[index];
        if (arg === "--dry-run") dryRun = true;
        else if (arg === "--repo") repo = argv[++index] ?? repo;
        else if (arg === "--run-url") runUrl = argv[++index] ?? runUrl;
        else if (arg === "--help" || arg === "-h") {
            console.log("usage: bun scripts/ci/required-check-audit.ts [--dry-run] [--repo OWNER/REPO] [--run-url URL]");
            process.exit(0);
        } else {
            console.error(`unknown argument: ${arg}`);
            process.exit(2);
        }
    }
    return { dryRun, repo, runUrl };
}

function loadSnapshot(): Record<string, any> {
    return JSON.parse(readFileSync(snapshotPath, "utf8"));
}

function gh(args: string[], input?: string): { status: number; stdout: string; stderr: string } {
    const result = spawnSync("gh", args, {
        encoding: "utf8",
        input,
        env: process.env,
    });
    return {
        status: result.status ?? 1,
        stdout: result.stdout ?? "",
        stderr: result.stderr ?? "",
    };
}

function findOpenAuditIssue(repo: string): number | null {
    const listed = gh([
        "issue", "list",
        "--repo", repo,
        "--state", "open",
        "--search", `in:title "${REQUIRED_CHECK_AUDIT_ISSUE_TITLE}"`,
        "--json", "number,title",
        "--limit", "20",
    ]);
    if (listed.status !== 0) {
        throw new Error(`gh issue list failed: ${listed.stderr || listed.stdout}`);
    }
    const rows = JSON.parse(listed.stdout || "[]") as Array<{ number: number; title: string }>;
    const exact = rows.find((row) => row.title === REQUIRED_CHECK_AUDIT_ISSUE_TITLE);
    return exact?.number ?? null;
}

function upsertAuditIssue(repo: string, body: string): number {
    const existing = findOpenAuditIssue(repo);
    if (existing != null) {
        const updated = gh(["issue", "edit", String(existing), "--repo", repo, "--body", body]);
        if (updated.status !== 0) {
            throw new Error(`gh issue edit failed: ${updated.stderr || updated.stdout}`);
        }
        return existing;
    }
    const created = gh([
        "issue", "create",
        "--repo", repo,
        "--title", REQUIRED_CHECK_AUDIT_ISSUE_TITLE,
        "--body", body,
    ]);
    if (created.status !== 0) {
        throw new Error(`gh issue create failed: ${created.stderr || created.stdout}`);
    }
    const match = created.stdout.trim().match(/\/issues\/(\d+)/);
    if (!match) {
        throw new Error(`gh issue create succeeded without an issue URL: ${created.stdout}`);
    }
    return Number(match[1]);
}

function withFakeMissing(snapshot: Record<string, any>): Record<string, any> {
    const changed = structuredClone(snapshot);
    const rule = changed.rules.find((entry: any) => entry.type === "required_status_checks");
    rule.parameters.required_status_checks.push({
        context: FAKE_MISSING,
        integration_id: 15368,
    });
    return changed;
}

function main(): number {
    const { dryRun, repo, runUrl } = parseArgs(process.argv.slice(2));
    const workflows = readWorkflows(root);
    const snapshot = dryRun ? withFakeMissing(loadSnapshot()) : loadSnapshot();
    let missing: RequiredCheck[];
    try {
        missing = missingRequiredChecks(snapshot, workflows);
    } catch (error) {
        console.error(error instanceof Error ? error.message : String(error));
        return 1;
    }

    if (missing.length === 0) {
        console.log("Required-check producer audit passed.");
        return 0;
    }

    const body = requiredCheckAuditIssueBody(missing, runUrl || undefined);
    console.error(`Required checks without a PR producer: ${formatMissingRequiredChecks(missing)}`);
    console.log(body);

    if (dryRun) {
        console.log(`dry-run: would open or update issue titled "${REQUIRED_CHECK_AUDIT_ISSUE_TITLE}" in ${repo}`);
        return 0;
    }

    const number = upsertAuditIssue(repo, body);
    console.error(`Reported on issue #${number}`);
    return 1;
}

process.exit(main());
