import { expect, test } from "bun:test";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
const root = resolve(import.meta.dir, "../..");

test("disjoint shards union covered lines without duplicating their shared source inventory", () => {
    const fixture = mkdtempSync(join(tmpdir(), "clover-union-"));
    try {
        const reports = ["a", "b"].map((name, index) => {
            const path = join(fixture, `${name}.xml`);
            writeFileSync(path, `<coverage><project><package><file name="/app/Shared.php">
              <line num="10" type="stmt" count="${index === 0 ? 1 : 0}"/>
              <line num="20" type="stmt" count="${index === 1 ? 1 : 0}"/>
              <line num="5" type="method" count="1"/>
              <metrics statements="2" coveredstatements="1"/>
            </file></package><metrics statements="2" coveredstatements="1"/></project></coverage>`);
            return path;
        });
        const measure = (...paths: string[]) => Bun.spawnSync(["python3", "scripts/ci/platform-coverage-ratchet.py", "measure", ...paths], { cwd: root });
        const merged = measure(...reports);
        expect(merged.exitCode).toBe(0);
        expect(merged.stdout.toString()).toContain("line_rate=100.0000 covered=2 statements=2");
        expect(measure(reports[0], reports[0]).stdout.toString()).toContain("line_rate=50.0000 covered=1 statements=2");
        const baseline = join(fixture, "baseline.json");
        writeFileSync(baseline, JSON.stringify({ line_rate: 100, tolerance_pp: 0 }));
        const check = Bun.spawnSync(["python3", "scripts/ci/platform-coverage-ratchet.py", "check", ...reports, "--baseline", baseline], { cwd: root });
        expect(check.exitCode).toBe(0);
        const anonymous = join(fixture, "metrics-only.xml");
        writeFileSync(anonymous, '<coverage><project><metrics statements="2" coveredstatements="2"/></project></coverage>');
        const refused = measure(anonymous);
        expect(refused.exitCode).not.toBe(0);
        expect(refused.stderr.toString()).toContain("no statement line identities");
    } finally { rmSync(fixture, { recursive: true, force: true }); }
});
