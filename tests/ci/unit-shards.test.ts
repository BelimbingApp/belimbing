import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
const root = resolve(import.meta.dir, "../..");

test("committed Unit shards cover the real surface", () => {
    const result = Bun.spawnSync(["python3", "scripts/ci/platform-feature-shards.py", "--suite=Unit", "--validate-only"], { cwd: root });
    expect(result.exitCode).toBe(0);
    expect(result.stdout.toString()).toContain("2 Unit shards");
});

test("Unit shards reject loose tests and unassigned directories", () => {
    const fixture = mkdtempSync(join(tmpdir(), "unit-shards-"));
    try {
        mkdirSync(join(fixture, "tests/Unit/Base"), { recursive: true });
        writeFileSync(join(fixture, "tests/Unit/Base/OneTest.php"), "<?php");
        const shards = join(fixture, "shards.json");
        writeFileSync(shards, JSON.stringify({ shards: { a: ["Base"] } }));
        const run = () => Bun.spawnSync(["python3", join(root, "scripts/ci/platform-feature-shards.py"), "--suite=Unit", "--root", fixture, "--shards-file", shards, "--validate-only"]);
        expect(run().exitCode).toBe(0);
        writeFileSync(join(fixture, "tests/Unit/LooseTest.php"), "<?php");
        expect(run().exitCode).not.toBe(0);
        expect(run().stderr.toString()).toContain("loose files: LooseTest.php");
        rmSync(join(fixture, "tests/Unit/LooseTest.php"));
        mkdirSync(join(fixture, "tests/Unit/Other"));
        writeFileSync(join(fixture, "tests/Unit/Other/TwoTest.php"), "<?php");
        expect(run().exitCode).not.toBe(0);
        expect(run().stderr.toString()).toContain("unsharded directories: Other");
    } finally { rmSync(fixture, { recursive: true, force: true }); }
});
