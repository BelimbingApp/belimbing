import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const script = join(root, "scripts/ci/domain-registry.php");

const run = (args: string[], env: Record<string, string> = {}) =>
    Bun.spawnSync(["php", script, ...args], { env: { ...process.env, ...env } });

test("every controlled Domain's repo, mount path and Sonar key derive from its id", () => {
    const result = run(["--json"], { BLB_DOMAIN_OWNERS: "BelimbingApp" });
    expect(result.exitCode).toBe(0);
    const registry = JSON.parse(result.stdout.toString());

    // These are the four entries the descriptor used to spell out by hand.
    expect(registry.domains).toEqual({
        people: {
            repo: "BelimbingApp/blb-people",
            repo_candidates: ["BelimbingApp/blb-people"],
            path: "app/Domains/People",
            sonar_project_key: "BelimbingApp_blb-people",
        },
        commerce: {
            repo: "BelimbingApp/blb-commerce",
            repo_candidates: ["BelimbingApp/blb-commerce"],
            path: "app/Domains/Commerce",
            sonar_project_key: "BelimbingApp_blb-commerce",
        },
        operation: {
            repo: "BelimbingApp/blb-operation",
            repo_candidates: ["BelimbingApp/blb-operation"],
            path: "app/Domains/Operation",
            sonar_project_key: "BelimbingApp_blb-operation",
        },
        "people-connector": {
            repo: "BelimbingApp/blb-people-connector",
            repo_candidates: ["BelimbingApp/blb-people-connector"],
            path: "app/Domains/PeopleConnector",
            sonar_project_key: "BelimbingApp_blb-people-connector",
        },
    });
});

test("the owner comes from this checkout's own remotes, origin before upstream", () => {
    const fixture = mkdtempSync(join(tmpdir(), "domain-registry-"));
    try {
        const git = (...args: string[]) => {
            const result = Bun.spawnSync(["git", "-C", fixture, ...args]);
            expect(result.exitCode).toBe(0);
        };
        git("init", "-q");
        git("remote", "add", "origin", "git@github.com:forker/belimbing.git");
        git("remote", "add", "upstream", "https://github.com/BelimbingApp/belimbing.git");

        // A fork resolves to its own owner first and falls back to the
        // repository it forked, which is where the Domains it does not host
        // itself will be.
        const result = run([`--root=${fixture}`, "--json"], { BLB_DOMAIN_OWNERS: "" });
        expect(result.exitCode).toBe(0);
        const registry = JSON.parse(result.stdout.toString());

        expect(registry.owners).toEqual(["forker", "BelimbingApp"]);
        expect(registry.domains.people.repo_candidates).toEqual([
            "forker/blb-people",
            "BelimbingApp/blb-people",
        ]);
        expect(registry.domains.people.sonar_project_key).toBe("forker_blb-people");
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});

test("a checkout with no usable remote is refused rather than guessed at", () => {
    const fixture = mkdtempSync(join(tmpdir(), "domain-registry-bare-"));
    try {
        mkdirSync(join(fixture, "empty"), { recursive: true });
        const result = run([`--root=${join(fixture, "empty")}`, "--json"], { BLB_DOMAIN_OWNERS: "" });
        expect(result.exitCode).toBe(2);
        expect(result.stderr.toString()).toContain("BLB_DOMAIN_OWNERS");
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});

test("a descriptor that is not the current schema, or names an unusable id, is refused", () => {
    const fixture = mkdtempSync(join(tmpdir(), "domain-registry-bad-"));
    try {
        const convention = { repo_prefix: "blb-", mount_root: "app/Domains", sonar_separator: "_" };
        const cases: Array<[string, unknown, string]> = [
            ["old schema", { schema_version: 1, convention, domains: ["people"] }, "schema_version 2"],
            ["no convention", { schema_version: 2, domains: ["people"] }, "convention block"],
            ["empty list", { schema_version: 2, convention, domains: [] }, "at least one domain"],
            ["upper case id", { schema_version: 2, convention, domains: ["People"] }, "lowercase"],
            ["duplicate id", { schema_version: 2, convention, domains: ["people", "people"] }, "listed twice"],
        ];

        for (const [label, descriptor, expected] of cases) {
            const path = join(fixture, "descriptor.json");
            writeFileSync(path, JSON.stringify(descriptor));
            const result = run([`--registry=${path}`, "--json"], { BLB_DOMAIN_OWNERS: "BelimbingApp" });
            expect(result.exitCode, label).toBe(2);
            expect(result.stderr.toString(), label).toContain(expected);
        }
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
