import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";

// Protect both running AND pending main runs: cancel-in-progress:false alone
// still lets a later run evict a pending run from a shared concurrency group.
for (const file of ["tests.yml", "lint.yml", "domain-ci.yml"]) {
    test(`${file} supersedes only the same PR/ref and isolates every main run`, () => {
        const workflow = Bun.YAML.parse(readFileSync(resolve(import.meta.dir,
            "../../.github/workflows", file), "utf8")) as any;
        const prefix = file === "domain-ci.yml" ? "domain-ci-" : "";
        expect(workflow.concurrency).toEqual({
            group: prefix + "${{ github.workflow }}-${{ github.ref }}-${{ github.ref == 'refs/heads/main' && github.run_id || 'pr' }}",
            "cancel-in-progress": "${{ github.ref != 'refs/heads/main' }}",
        });
    });
}
