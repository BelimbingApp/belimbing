import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
test("pin advance validates before composing, then pushes a compare URL (no Actions PR create)", () => {
    const workflow = Bun.YAML.parse(readFileSync(join(root, ".github/workflows/advance-domain-pin.yml"), "utf8")) as any;
    expect(Object.keys(workflow.on)).toEqual(["workflow_dispatch"]);
    expect(workflow.on.workflow_dispatch.inputs["domain-id"].required).toBe(true);
    const descriptor = JSON.parse(readFileSync(join(root, "scripts/ci/domain-repos.json"), "utf8"));
    expect(workflow.on.workflow_dispatch.inputs["domain-id"].options.toSorted()).toEqual(Object.keys(descriptor.domains).sort());
    expect(workflow.on.workflow_dispatch.inputs.ref.required).toBe(true);
    expect(workflow.jobs.compose.permissions.contents).toBe("read");
    const steps = workflow.jobs.compose.steps;
    const validate = steps.findIndex((step: any) => step.name === "Validate and advance pin");
    const compose = steps.findIndex((step: any) => step.name === "Regenerate composed surface");
    expect(validate).toBeGreaterThan(-1);
    expect(compose).toBeGreaterThan(validate);
    expect(steps[validate].run).toContain("advance-domain-pin.py");
    expect(readFileSync(join(root, "scripts/ci/advance-domain-pin.py"), "utf8")).toContain("validate-domain-pins.py");
    expect(steps[compose].run).toContain("composed-smoke.php --print-surface");
    // GITHUB_TOKEN cannot createPullRequest when the org setting is off (#793):
    // push the branch and print a compare URL instead of failing after a good push.
    expect(workflow.jobs.publish.permissions["pull-requests"]).toBeUndefined();
    const publish = workflow.jobs.publish.steps.find((step: any) => step.name === "Push pin branch").run;
    expect(publish).toContain("git push origin");
    expect(publish).toContain("/compare/main...");
    expect(publish).toContain("Open the bot-maintenance PR from:");
    expect(publish).not.toContain("gh pr create");
    expect(publish).not.toContain("HEAD:main");
    // `gh pr create --label bot-maintenance` used to apply the label that
    // ai-team-independent-review.yml reads to grant the bot exemption
    // ("no bot-maintenance label — ordinary review required"). A human opening
    // the PR from the compare URL applies it by hand, so the step has to say
    // so, or the exemption silently stops applying to this path.
    expect(publish).toContain("Apply the bot-maintenance label");
});

test("pin editor refuses invalid or nonexistent refs without altering the descriptor", () => {
    const fixture = mkdtempSync(join(tmpdir(), "advance-pin-"));
    try {
        const descriptor = join(fixture, "domain-repos.json");
        const original = readFileSync(join(root, "scripts/ci/domain-repos.json"), "utf8");
        writeFileSync(descriptor, original);
        const resolver = join(fixture, "resolve");
        writeFileSync(resolver, '#!/bin/sh\nprintf \'{"exists":false}\\n\'\n', { mode: 0o755 });
        const run = (ref: string) => Bun.spawnSync(["python3", join(root, "scripts/ci/advance-domain-pin.py"), "people", ref, "--descriptor", descriptor, "--resolver", resolver]);
        expect(run("main").exitCode).not.toBe(0);
        expect(readFileSync(descriptor, "utf8")).toBe(original);
        const unknown = Bun.spawnSync(["python3", join(root, "scripts/ci/advance-domain-pin.py"), "unknown", "a".repeat(40), "--descriptor", descriptor]);
        expect(unknown.exitCode).not.toBe(0);
        expect(unknown.stderr.toString()).toContain("Domain in the descriptor");
        expect(readFileSync(descriptor, "utf8")).toBe(original);
        expect(run("a".repeat(40)).exitCode).not.toBe(0);
        expect(readFileSync(descriptor, "utf8")).toBe(original);
        writeFileSync(resolver, '#!/bin/sh\nprintf \'{"exists":true,"behind":0}\\n\'\n', { mode: 0o755 });
        expect(run("a".repeat(40)).exitCode).toBe(0);
        const expected = JSON.parse(original);
        expected.domains.people.ref = "a".repeat(40);
        expect(JSON.parse(readFileSync(descriptor, "utf8"))).toEqual(expected);
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});

test("bot policy accepts pin artifacts alone and refuses an unrelated file", () => {
    const fixture = mkdtempSync(join(tmpdir(), "pin-policy-"));
    try {
        const git = (...args: string[]) => {
            const result = Bun.spawnSync(["git", ...args], { cwd: fixture });
            expect(result.exitCode).toBe(0);
            return result.stdout.toString().trim();
        };
        git("init", "-q");
        git("config", "user.name", "test");
        git("config", "user.email", "test@example.invalid");
        git("commit", "--allow-empty", "-qm", "base");
        const base = git("rev-parse", "HEAD");
        const files = ["scripts/ci/domain-repos.json", "scripts/ci/composed-surface.json"];
        mkdirSync(join(fixture, "scripts/ci"), { recursive: true });
        for (const path of files) {
            writeFileSync(join(fixture, path), "{}\n");
        }
        git("add", ".");
        git("commit", "-qm", "pin");
        const policy = () => Bun.spawnSync(["bash", join(root, "scripts/ci/bot-pr-policy.sh"), base, "HEAD"], { cwd: fixture });
        expect(policy().exitCode).toBe(0);
        writeFileSync(join(fixture, "README.md"), "unexpected\n");
        git("add", ".");
        git("commit", "-qm", "extra");
        const refused = policy();
        expect(refused.exitCode).toBe(1);
        expect(refused.stderr.toString()).toContain("README.md");
        git("checkout", "-q", "--detach", base);
        mkdirSync(join(fixture, "tests/ci"), { recursive: true });
        writeFileSync(join(fixture, "tests/ci/pest-timing-baseline.json"), "{}\n");
        git("add", ".");
        git("commit", "-qm", "timing baseline");
        expect(policy().exitCode).toBe(0);
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
