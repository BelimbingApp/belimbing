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

// GitHub branch filters use regex-style ?/+ quantifiers, not shell glob ?.
// https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#filter-pattern-cheat-sheet
function matchesMain(pattern: string): boolean {
    let expression = "^";
    for (let index = 0; index < pattern.length; index++) {
        const character = pattern[index];
        if (character === "\\") {
            if (++index === pattern.length) throw new Error("Incomplete branch filter escape");
            expression += "\\" + pattern[index];
        } else if (character === "*") {
            if (pattern[index + 1] === "*") {
                index++;
                expression += ".*";
            } else expression += "[^/]*";
        } else if (character === "[") {
            const end = pattern.indexOf("]", index + 1);
            const members = pattern.slice(index + 1, end);
            if (end < 0 || !/^[a-zA-Z0-9-]+$/.test(members)) {
                throw new Error("Unsupported branch filter character class: " + pattern);
            }
            expression += "[" + members + "]";
            index = end;
        } else if (character === "?" || character === "+") {
            expression += character;
        } else {
            expression += character.replace(/[\\^$.*+?()[\]{}|]/g, "\\$&");
        }
    }
    return new RegExp(expression + "$").test("main");
}

function targetsMain(filters: Mapping | null): boolean {
    if (!filters) return true;
    if (filters.branches && filters["branches-ignore"]) return false;
    if (filters.branches) {
        let included = false;
        for (const pattern of filters.branches) {
            const negative = pattern.startsWith("!");
            if (matchesMain(negative ? pattern.slice(1) : pattern)) included = !negative;
        }
        return included;
    }
    return !(filters["branches-ignore"] ?? []).some(matchesMain);
}

function hasPullRequestTrigger(trigger: unknown): boolean {
    if (typeof trigger === "string") return ["pull_request", "pull_request_target"].includes(trigger);
    if (Array.isArray(trigger)) return trigger.some(hasPullRequestTrigger);
    return !!trigger && typeof trigger === "object" &&
        ["pull_request", "pull_request_target"].some((key) =>
            key in trigger && targetsMain((trigger as Mapping)[key]));
}

function explicitlyDisabled(condition: unknown): boolean {
    if (condition === false) return true;
    if (typeof condition !== "string") return false;
    const value = condition.trim();
    return value === "false" || /^\$\{\{\s*false\s*\}\}$/.test(value);
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
            if (explicitlyDisabled(job.if)) continue;
            // Reusable calls and matrices do not emit the bare caller ID.
            // Dynamic names must never falsely satisfy a static required check.
            if (!job.uses && !job.strategy?.matrix) {
                const name = job.name ?? id;
                if (typeof name === "string" && !name.includes("$" + "{{")) add(name, ACTIONS_APP);
            }
            // Sonar's App emits this context from the actual PR scan step.
            if ((job.steps ?? []).some((step: Mapping) =>
                typeof step.uses === "string" &&
                /^SonarSource\/sonarqube-scan-action@/.test(step.uses) &&
                step.env?.SONAR_HOST_URL === "https://sonarcloud.io" &&
                !explicitlyDisabled(step.if),
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
