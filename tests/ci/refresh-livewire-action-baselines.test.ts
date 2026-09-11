import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dir, "../..");
const workflowPath = join(root, ".github/workflows/refresh-livewire-action-baselines.yml");
const workflow = () => Bun.YAML.parse(readFileSync(workflowPath, "utf8")) as any;

test("livewire baseline refresh is dispatch-only, opens a PR, and never pushes to main", () => {
    const config = workflow();
    expect(Object.keys(config.on)).toEqual(["workflow_dispatch"]);
    expect(config.on.push).toBeUndefined();
    expect(config.on.pull_request).toBeUndefined();

    const steps = config.jobs.refresh.steps;
    expect(steps[0].with.ref).toBe("main");

    const platform = steps.find((step: any) => step.name === "Refresh platform baseline before Domains mount");
    expect(platform.run).toContain("blb:livewire-actions --write-baseline=");
    expect(platform.run).toContain("scripts/ci/refresh-livewire-action-baselines.py");
    expect(platform.run).toContain("tests/ci/livewire-actions-baselines/platform.json");

    const compose = steps.find((step: any) => step.name === "Compose the Domains");
    expect(compose.run).toContain("scripts/ci/domain-registry.php --materialize");
    expect(compose.run).not.toContain("git clone");

    const domains = steps.find((step: any) => step.name === "Refresh Domain baselines");
    expect(domains.run).toContain("--domain=");
    expect(domains.run).toContain("livewire-actions-baselines/${id}.json");
    expect(domains.run).toContain("refresh-livewire-action-baselines.py");

    const openPr = steps.find((step: any) => step.name === "Open baseline PR");
    expect(config.jobs.refresh.permissions["pull-requests"]).toBeUndefined();
    expect(openPr.env.COVERAGE_BASELINE_RAISE_TOKEN).toBe("${{ secrets.COVERAGE_BASELINE_RAISE_TOKEN }}");
    expect(openPr.run).toContain("COVERAGE_BASELINE_RAISE_TOKEN is required");
    expect(openPr.run).toContain('export GH_TOKEN="$COVERAGE_BASELINE_RAISE_TOKEN"');
    expect(openPr.run).toContain("gh pr create");
    expect(openPr.run).toContain("--label bot-maintenance");
    expect(openPr.run).toContain("AI-Team-Lane-Issue: none");
    expect(openPr.run).toContain("tests/ci/livewire-actions-baselines");
    expect(openPr.run).toContain("Protect Main refuses direct pushes");
    expect(openPr.run).toContain('git push origin "HEAD:refs/heads/$branch"');
    expect(openPr.run).not.toMatch(/push[^\n]*HEAD:main/);
    expect(openPr.run).not.toMatch(/git push[^\n]*\smain\b/);
});
