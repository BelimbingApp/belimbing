#!/usr/bin/env bun
/**
 * Opens or updates the single issue titled "Domain pins stale" when the
 * nightly pin validator reports a Domain more than 50 commits behind main.
 * When no Domain is stale, the existing issue is closed.
 *
 * --dry-run: print a synthetic stale-pin issue body without calling GitHub.
 */
import { readFileSync } from "node:fs";
import {
    closeOpenIssue,
    upsertIssue,
} from "./composed-boot-failure-report";

export const DOMAIN_PIN_STALE_ISSUE_TITLE = "Domain pins stale";
export const DOMAIN_PIN_STALE_ISSUE_MARKER = "<!-- domain-pins-stale -->";

const FAKE_VALIDATION = [
    "WARN people: pin is 51 commits behind main (BelimbingApp/blb-people@1111111111111111111111111111111111111111)",
    "OK people-connector: pinned commit exists, 3 commits behind main",
    "WARN people-connector: pin is 73 commits behind main (BelimbingApp/blb-people-connector@2222222222222222222222222222222222222222)",
].join("\n");

export type StaleDomainPin = {
    name: string;
    repo: string;
    ref: string;
    behind: number;
};

const WARNING_PATTERN = /^WARN ([^:]+): pin is (\d+) commits behind main \(([^@]+)@([0-9a-f]{40})\)$/;

export function staleDomainPins(validationOutput: string): StaleDomainPin[] {
    return validationOutput
        .split(/\r?\n/)
        .filter((line) => line.startsWith("WARN "))
        .map((line) => {
            const match = line.match(WARNING_PATTERN);
            if (!match) throw new Error(`invalid stale Domain pin warning: ${line}`);
            return {
                name: match[1],
                behind: Number(match[2]),
                repo: match[3],
                ref: match[4],
            };
        });
}

export function domainPinsStaleIssueBody(pins: StaleDomainPin[], runUrl?: string): string {
    const lines = [
        DOMAIN_PIN_STALE_ISSUE_MARKER,
        "The nightly composed-application smoke test found Domain pins more than 50 commits behind `main`.",
        "",
        "## Stale pins",
        "",
        "| Domain | Repository | Pinned commit | Commits behind `main` |",
        "| --- | --- | --- | ---: |",
        ...pins.map((pin) =>
            "| `" + pin.name + "` | `" + pin.repo + "` | `" + pin.ref + "` | " + pin.behind + " |",
        ),
        "",
    ];
    if (runUrl) lines.push(`Run: ${runUrl}`, "");
    lines.push(
        "Advance each immutable ref through the reviewed pin-update flow in `docs/ci/domain-pins.md`.",
        "",
        "This issue is opened or updated by the nightly `composed-smoke` workflow and is closed automatically when all pins are current.",
    );
    return lines.join("\n");
}

function parseArgs(argv: string[]): {
    dryRun: boolean;
    repo: string;
    runUrl: string;
    warningsFile: string;
} {
    let dryRun = false;
    let repo = process.env.GITHUB_REPOSITORY ?? "BelimbingApp/belimbing";
    let runUrl = process.env.GITHUB_RUN_URL ?? "";
    let warningsFile = process.env.DOMAIN_PIN_VALIDATION_FILE ?? "";
    for (let index = 0; index < argv.length; index++) {
        const arg = argv[index];
        if (arg === "--dry-run") dryRun = true;
        else if (arg === "--repo") repo = argv[++index] ?? repo;
        else if (arg === "--run-url") runUrl = argv[++index] ?? runUrl;
        else if (arg === "--warnings-file") warningsFile = argv[++index] ?? warningsFile;
        else if (arg === "--help" || arg === "-h") {
            console.log(
                "usage: bun scripts/ci/domain-pin-stale-report.ts [--dry-run] [--repo OWNER/REPO] [--run-url URL] [--warnings-file PATH]",
            );
            process.exit(0);
        } else {
            console.error(`unknown argument: ${arg}`);
            process.exit(2);
        }
    }
    return { dryRun, repo, runUrl, warningsFile };
}

function main(): number {
    const { dryRun, repo, runUrl, warningsFile } = parseArgs(process.argv.slice(2));
    const output = dryRun
        ? FAKE_VALIDATION
        : warningsFile
            ? readFileSync(warningsFile, "utf8")
            : process.env.DOMAIN_PIN_VALIDATION ?? "";
    const pins = staleDomainPins(output);

    if (pins.length === 0) {
        console.log("No stale Domain pins.");
        if (dryRun) {
            console.log(`dry-run: would close an open issue titled "${DOMAIN_PIN_STALE_ISSUE_TITLE}" in ${repo}`);
            return 0;
        }
        const closed = closeOpenIssue(repo, DOMAIN_PIN_STALE_ISSUE_TITLE);
        console.error(closed == null ? "No stale-pin issue is open." : `Closed issue #${closed}`);
        return 0;
    }

    const body = domainPinsStaleIssueBody(pins, runUrl || undefined);
    console.log(body);
    if (dryRun) {
        console.log(`dry-run: would open or update issue titled "${DOMAIN_PIN_STALE_ISSUE_TITLE}" in ${repo}`);
        return 0;
    }
    const number = upsertIssue(repo, DOMAIN_PIN_STALE_ISSUE_TITLE, body);
    console.error(`Reported on issue #${number}`);
    return 0;
}

if (import.meta.main) process.exit(main());
