import { expect, test } from "bun:test";
import { readFileSync } from "node:fs";
import { join } from "node:path";

test("baseline refresh composes pinned domains without write credentials and publishes one PR", () => {
    const workflow = Bun.YAML.parse(readFileSync(join(import.meta.dir, "../../.github/workflows/refresh-livewire-action-baselines.yml"), "utf8")) as any;
    expect(Object.keys(workflow.on)).toEqual(["workflow_dispatch"]);
    expect(workflow.jobs.compose.permissions).toEqual({ contents: "read" });
    const compose = workflow.jobs.compose.steps.map((step: any) => step.run ?? "").join("\n");
    expect(compose).toContain(".domains | keys[]");
    expect(compose).toContain('checkout --detach "$ref"');
    expect(compose).toContain("blb:livewire-actions --write-baseline");
    expect(compose).toContain('blb:livewire-actions --domain="$domain" --write-baseline');
    expect(compose).toContain("refresh-livewire-action-baselines.py");
    expect(workflow.jobs.publish.needs).toBe("compose");
    const publish = workflow.jobs.publish.steps.map((step: any) => step.run ?? "").join("\n");
    expect(publish.match(/gh pr create/g)).toHaveLength(1);
    expect(publish).toContain("--label bot-maintenance");
    expect(publish).toContain('HEAD:refs/heads/$branch');
    expect(publish).not.toMatch(/git push[^\n]*\smain\b/);
});
