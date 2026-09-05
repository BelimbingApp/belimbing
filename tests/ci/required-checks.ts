import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

type Mapping = Record<string, any>;
const ACTIONS_APP = 15368;
const SONAR_APP = 12526;

export function readWorkflows(root: string): Mapping {
    return Object.fromEntries(
        readdirSync(join(root, ".github/workflows"))
            .filter((name) => /\.ya?ml$/.test(name))
            .map((name) => [name, Bun.YAML.parse(readFileSync(join(root, ".github/workflows", name), "utf8"))]),
    );
}

function hasPullRequestTrigger(trigger: unknown): boolean {
    if (typeof trigger === "string") return ["pull_request", "pull_request_target"].includes(trigger);
    if (Array.isArray(trigger)) return trigger.some(hasPullRequestTrigger);
    return !!trigger && typeof trigger === "object" &&
        ["pull_request", "pull_request_target"].some((key) => key in trigger);
}

export function auditRequiredChecks(ruleset: Mapping, workflows: Mapping): string[] {
    if (ruleset.enforcement !== "active" || ruleset.target !== "branch" ||
        ruleset.source !== "BelimbingApp/belimbing" ||
        !ruleset.conditions?.ref_name?.include?.includes("refs/heads/main") ||
        ruleset.conditions?.ref_name?.exclude?.includes("refs/heads/main")) {
        throw new Error("The snapshot must describe the active branch ruleset.");
    }
    const required = ruleset.rules
        ?.filter((rule: Mapping) => rule.type === "required_status_checks")
        .flatMap((rule: Mapping) => rule.parameters?.required_status_checks ?? []);
    if (!required?.length) throw new Error("The ruleset snapshot contains no required checks.");

    const producers = new Map<string, Set<number>>();
    const add = (name: string, app: number) => {
        if (!producers.has(name)) producers.set(name, new Set());
        producers.get(name)!.add(app);
    };

    for (const workflow of Object.values(workflows) as Mapping[]) {
        if (!hasPullRequestTrigger(workflow.on)) continue;
        for (const [id, job] of Object.entries(workflow.jobs ?? {}) as [string, Mapping][]) {
            // Reusable calls and matrices do not emit the bare caller ID.
            // Dynamic names must never falsely satisfy a static required check.
            if (!job.uses && !job.strategy?.matrix) {
                const name = job.name ?? id;
                if (typeof name === "string" && !name.includes("$" + "{{")) add(name, ACTIONS_APP);
            }
            // Sonar's App emits this context from the actual PR scan step.
            if (job.if !== false && job.if !== "false" && (job.steps ?? []).some((step: Mapping) =>
                typeof step.uses === "string" &&
                /^SonarSource\/sonarqube-scan-action@/.test(step.uses) &&
                step.env?.SONAR_HOST_URL === "https://sonarcloud.io" &&
                step.if !== false && step.if !== "false",
            )) add("SonarCloud Code Analysis", SONAR_APP);
        }
    }

    const missing = required.filter((check: Mapping) => {
        const apps = producers.get(check.context);
        return !apps || (check.integration_id != null && !apps.has(check.integration_id));
    });
    if (missing.length) {
        throw new Error("Required checks without a PR producer: " +
            missing.map((check: Mapping) => check.context + " (app " + (check.integration_id ?? "any") + ")").join(", "));
    }
    return required.map((check: Mapping) => check.context);
}
