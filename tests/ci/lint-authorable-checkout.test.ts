import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

// #857: Pint on changed authorable PHP assumes the quality job's working tree
// is checked out at head. A `ref:` override (or any checkout that is not the
// PR tip) would leave [[ -f ]] dropping every added file and silently lint an
// empty set. Keep that precondition a workflow contract, not a comment.
const root = resolve(import.meta.dir, "../..");
const lint = Bun.YAML.parse(readFileSync(resolve(root, ".github/workflows/lint.yml"), "utf8")) as any;
const steps: any[] = lint.jobs.quality.steps;

test("quality checkout has no ref override so the tree is at head", () => {
    const checkout = steps.find((s) => typeof s.uses === "string" && s.uses.startsWith("actions/checkout@"));
    expect(checkout).toBeDefined();
    expect(checkout.with?.ref).toBeUndefined();
    expect(checkout.with?.["fetch-depth"]).toBe(0);
});

test("changed-authorable-php.sh runs after the quality checkout", () => {
    const checkout = steps.findIndex((s) => typeof s.uses === "string" && s.uses.startsWith("actions/checkout@"));
    const authorable = steps.findIndex(
        (s) => typeof s.run === "string" && s.run.includes("scripts/ci/changed-authorable-php.sh"),
    );
    expect(checkout).toBeGreaterThan(-1);
    expect(authorable).toBeGreaterThan(-1);
    expect(authorable).toBeGreaterThan(checkout);
});
