#!/usr/bin/env bun
/**
 * Opens or updates the single issue titled "Composed boot failed" when the
 * scheduled composed-app smoke refuses (belimbing#663).
 *
 * --dry-run: print the issue body for a synthetic refusal and exit 0 without
 * calling the GitHub Issues API.
 */
import { spawnSync } from "node:child_process";

export const COMPOSED_BOOT_FAILED_ISSUE_TITLE = "Composed boot failed";
const FAKE_REFUSAL = "composed-smoke: dry-run refusal (synthetic)";

function parseArgs(argv: string[]): {
    dryRun: boolean;
    repo: string;
    runUrl: string;
    message: string;
} {
    let dryRun = false;
    let repo = process.env.GITHUB_REPOSITORY ?? "BelimbingApp/belimbing";
    let runUrl = process.env.GITHUB_RUN_URL ?? "";
    let message = process.env.COMPOSED_SMOKE_REFUSAL ?? "";
    for (let index = 0; index < argv.length; index++) {
        const arg = argv[index];
        if (arg === "--dry-run") dryRun = true;
        else if (arg === "--repo") repo = argv[++index] ?? repo;
        else if (arg === "--run-url") runUrl = argv[++index] ?? runUrl;
        else if (arg === "--message") message = argv[++index] ?? message;
        else if (arg === "--help" || arg === "-h") {
            console.log(
                "usage: bun scripts/ci/composed-boot-failure-report.ts [--dry-run] [--repo OWNER/REPO] [--run-url URL] [--message TEXT]",
            );
            process.exit(0);
        } else {
            console.error(`unknown argument: ${arg}`);
            process.exit(2);
        }
    }
    return { dryRun, repo, runUrl, message };
}

export function composedBootFailedIssueBody(refusal: string, runUrl?: string): string {
    const lines = [
        "The scheduled composed-application smoke test refused at the pins in `scripts/ci/domain-repos.json`.",
        "",
        "## Refusal",
        "",
        "```",
        refusal.trim() || "(no refusal message captured)",
        "```",
        "",
    ];
    if (runUrl) {
        lines.push(`Run: ${runUrl}`, "");
    }
    lines.push(
        "See `docs/ci/composed-app-runbook.md` and `docs/ci/domain-pins.md`.",
        "",
        "This issue is opened or updated by the nightly `composed-smoke` workflow (#663). Close it when composition is green again.",
    );
    return lines.join("\n");
}

export function gh(args: string[]): { status: number; stdout: string; stderr: string } {
    const result = spawnSync("gh", args, {
        encoding: "utf8",
        env: process.env,
    });
    return {
        status: result.status ?? 1,
        stdout: result.stdout ?? "",
        stderr: result.stderr ?? "",
    };
}

export function findOpenIssue(repo: string, title: string): number | null {
    const listed = gh([
        "issue",
        "list",
        "--repo",
        repo,
        "--state",
        "open",
        "--search",
        `in:title "${title}"`,
        "--json",
        "number,title",
        "--limit",
        "20",
    ]);
    if (listed.status !== 0) {
        throw new Error(`gh issue list failed: ${listed.stderr || listed.stdout}`);
    }
    const rows = JSON.parse(listed.stdout || "[]") as Array<{ number: number; title: string }>;
    const exact = rows.find((row) => row.title === title);
    return exact?.number ?? null;
}

export function upsertIssue(repo: string, title: string, body: string): number {
    const existing = findOpenIssue(repo, title);
    if (existing != null) {
        const updated = gh(["issue", "edit", String(existing), "--repo", repo, "--body", body]);
        if (updated.status !== 0) {
            throw new Error(`gh issue edit failed: ${updated.stderr || updated.stdout}`);
        }
        return existing;
    }
    const created = gh([
        "issue",
        "create",
        "--repo",
        repo,
        "--title",
        title,
        "--body",
        body,
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

export function closeOpenIssue(repo: string, title: string): number | null {
    const existing = findOpenIssue(repo, title);
    if (existing == null) return null;
    const closed = gh(["issue", "close", String(existing), "--repo", repo]);
    if (closed.status !== 0) {
        throw new Error(`gh issue close failed: ${closed.stderr || closed.stdout}`);
    }
    return existing;
}

function main(): number {
    const { dryRun, repo, runUrl, message } = parseArgs(process.argv.slice(2));
    const refusal = dryRun ? FAKE_REFUSAL : message;
    if (!dryRun && !refusal.trim()) {
        console.error("composed-boot-failure-report: refusal message is required unless --dry-run");
        return 2;
    }

    const body = composedBootFailedIssueBody(refusal, runUrl || undefined);
    console.log(body);

    if (dryRun) {
        console.log(
            `dry-run: would open or update issue titled "${COMPOSED_BOOT_FAILED_ISSUE_TITLE}" in ${repo}`,
        );
        return 0;
    }

    const number = upsertIssue(repo, COMPOSED_BOOT_FAILED_ISSUE_TITLE, body);
    console.error(`Reported on issue #${number}`);
    return 0;
}

if (import.meta.main) {
    process.exit(main());
}
