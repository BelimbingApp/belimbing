import { expect, test } from "bun:test";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
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

test("a remote whose host merely resembles github.com yields no owner", () => {
    const fixture = mkdtempSync(join(tmpdir(), "domain-registry-host-"));
    try {
        const git = (...args: string[]) => {
            const result = Bun.spawnSync(["git", "-C", fixture, ...args]);
            expect(result.exitCode).toBe(0);
        };
        git("init", "-q");
        // Taking UnrelatedOwner here and then cloning github.com/UnrelatedOwner
        // would compose a repository this checkout never vouched for (#944 review).
        git("remote", "add", "origin", "https://github.internal.example/UnrelatedOwner/platform.git");
        git("remote", "add", "upstream", "git@gitlab.com:Other/platform.git");

        const result = run([`--root=${fixture}`, "--json"], { BLB_DOMAIN_OWNERS: "" });

        expect(result.exitCode).toBe(2);
        expect(result.stderr.toString()).toContain("BLB_DOMAIN_OWNERS");
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
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

/**
 * The fork fallback is only real if the path that actually puts code on disk
 * walks the candidates. Before #943's review it did not: the workflows read
 * --tsv, which emits the first candidate, and cloned that one directly, so a
 * fork that hosts only some of its Domains failed hard on the first miss.
 * These drive the materializer against a git stub.
 */
function gitStub(fixture: string, rejectPattern: string): string {
    const bin = join(fixture, "bin");
    mkdirSync(bin, { recursive: true });
    writeFileSync(
        join(bin, "git"),
        [
            "#!/usr/bin/env bash",
            "# Records every clone attempt, refuses the named owner, and makes a",
            "# real repository for anyone else so rev-parse HEAD answers.",
            'if [ "$1" = "clone" ]; then',
            '  url="${@: -2:1}"; dest="${@: -1}"',
            `  echo "$url" >> ${JSON.stringify(join(fixture, "attempts.txt"))}`,
            `  if [[ "$url" == *${rejectPattern}* ]]; then`,
            '    echo "remote: Repository not found." >&2; exit 128',
            "  fi",
            '  mkdir -p "$dest"',
            '  /usr/bin/git init -q "$dest"',
            '  /usr/bin/git -C "$dest" -c user.name=stub -c user.email=stub@example.invalid commit -q --allow-empty -m stub',
            "  exit 0",
            "fi",
            'exec /usr/bin/git "$@"',
        ].join("\n"),
        { mode: 0o755 },
    );
    return bin;
}

function materializeFixture(rejectPattern: string) {
    const fixture = mkdtempSync(join(tmpdir(), "domain-materialize-"));
    const descriptor = join(fixture, "descriptor.json");
    writeFileSync(
        descriptor,
        JSON.stringify({
            schema_version: 2,
            convention: { repo_prefix: "blb-", mount_root: "app/Domains", sonar_separator: "_" },
            domains: ["people"],
        }),
    );
    const bin = gitStub(fixture, rejectPattern);
    const result = Bun.spawnSync(["php", script, `--registry=${descriptor}`, `--root=${fixture}`, "--materialize"], {
        env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, BLB_DOMAIN_OWNERS: "forker,BelimbingApp" },
    });
    return { fixture, result };
}

test("a first candidate that does not host the Domain falls through to the next owner", () => {
    const { fixture, result } = materializeFixture("/forker/");
    try {
        expect(result.exitCode, result.stderr.toString()).toBe(0);

        const attempts = readFileSync(join(fixture, "attempts.txt"), "utf8").trim().split("\n");
        expect(attempts).toEqual([
            "https://github.com/forker/blb-people.git",
            "https://github.com/BelimbingApp/blb-people.git",
        ]);

        // It reports the owner it actually cloned from, not the first candidate.
        expect(result.stdout.toString()).toContain("BelimbingApp/blb-people");
        expect(existsSync(join(fixture, "app/Domains/People"))).toBeTrue();
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});

test("a Domain no candidate hosts fails naming every owner tried", () => {
    // Reject the repository name itself, so neither candidate answers.
    const { fixture, result } = materializeFixture("blb-people");
    try {
        expect(result.exitCode).toBe(1);

        const stderr = result.stderr.toString();
        expect(stderr).toContain("could not materialize");
        // Both attempts are named: a failure that hides the second owner
        // reads like the fallback was never tried.
        expect(stderr).toContain("forker/blb-people");
        expect(stderr).toContain("BelimbingApp/blb-people");
        expect(existsSync(join(fixture, "app/Domains/People"))).toBeFalse();
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
