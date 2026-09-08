import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

// #780: the quality job audits every secrets.* reference against the checked-in
// allowlist before anything that needs vendor/ is installed, so a stray secret
// name fails fast and the allowlist stays the single source of truth.
const root = resolve(import.meta.dir, "../..");
const lint = Bun.YAML.parse(readFileSync(resolve(root, ".github/workflows/lint.yml"), "utf8")) as any;
const steps: any[] = lint.jobs.quality.steps;

test("quality runs token-audit.sh unconditionally", () => {
    const step = steps.find((s) => s.run === "scripts/ci/token-audit.sh");
    expect(step).toBeDefined();
    expect(step.if).toBeUndefined();
    expect(step.env).toBeUndefined();
});

test("token-audit runs before the dependency install", () => {
    const audit = steps.findIndex((s) => s.run === "scripts/ci/token-audit.sh");
    const install = steps.findIndex((s) => s.name === "Install Dependencies");
    expect(audit).toBeGreaterThan(-1);
    expect(audit).toBeLessThan(install);
});

test("allowlist entries carry name, purpose, owner and an ISO rotation date", () => {
    const allowlist = JSON.parse(readFileSync(resolve(root, "docs/ci/secrets.json"), "utf8"));
    expect(Array.isArray(allowlist.secrets)).toBe(true);
    for (const entry of allowlist.secrets) {
        for (const field of ["name", "purpose", "owner", "rotated"]) {
            expect(typeof entry[field]).toBe("string");
            expect(entry[field].trim().length).toBeGreaterThan(0);
        }
        expect(entry.rotated).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    }
});
