import { expect, test } from "bun:test";
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve, dirname } from "node:path";

const root = resolve(import.meta.dir, "../..");
test("all descriptor pins and Commerce/Operation routes belong to the composed surface", () => {
    const fixture = mkdtempSync(join(tmpdir(), "all-domains-"));
    try {
        const domains: Record<string, any> = {};
        const pins: Record<string, string> = {};
        for (const name of ["people", "people-connector", "commerce", "operation"]) {
            const path = `app/Domains/${name}`;
            mkdirSync(join(fixture, path), { recursive: true });
            const git = (...args: string[]) => {
                const result = Bun.spawnSync(["git", ...args], { cwd: join(fixture, path) });
                expect(result.exitCode).toBe(0);
                return result.stdout.toString().trim();
            };
            git("init", "-q");
            git("-c", "user.name=test", "-c", "user.email=test@example.invalid", "commit", "--allow-empty", "-qm", name);
            pins[name] = git("rev-parse", "HEAD");
            domains[name] = { path, ref: pins[name], repo: `fixture/${name}` };
        }
        const names = ["commerce.catalog.index", "it.tickets.index", "people.index", "quality.ncr.index"];
        // Domain surface membership is live names matching DOMAIN_ROUTE_NAME (#916/#920).
        const declarations: Record<string, string> = {
            "commerce.catalog.index": "app/Domains/commerce/Catalog/Routes/web.php",
            "it.tickets.index": "app/Domains/operation/It/Routes/web.php",
            "people.index": "app/Domains/people/Workforce/Routes/web.php",
            "quality.ncr.index": "app/Domains/operation/Quality/Routes/web.php",
        };
        for (const [name, rel] of Object.entries(declarations)) {
            const file = join(fixture, rel);
            mkdirSync(dirname(file), { recursive: true });
            writeFileSync(file, `<?php\nRoute::get('${name}', fn () => null)->name('${name}');\n`);
        }
        const surface = { pins, domain_route_count: names.length, route_names: names };
        const registryPath = join(fixture, "registry.json");
        const surfacePath = join(fixture, "surface.json");
        const routesPath = join(fixture, "routes.json");
        writeFileSync(registryPath, JSON.stringify({ domains }));
        writeFileSync(surfacePath, JSON.stringify(surface));
        writeFileSync(routesPath, JSON.stringify([...names, "admin.system.index", "admin.integration.outbound-exchanges.index"].map(name => ({ name }))));
        const run = (...args: string[]) => Bun.spawnSync(["php", join(root, "scripts/ci/composed-smoke.php"), `--root=${fixture}`, `--registry=${registryPath}`, `--surface=${surfacePath}`, `--routes-json=${routesPath}`, ...args]);
        const printed = run("--print-surface");
        expect(printed.exitCode).toBe(0);
        const generated = JSON.parse(printed.stdout.toString());
        expect(generated.pins).toEqual(pins);
        expect(generated.route_names).toEqual(names);
        expect(run().exitCode).toBe(0);
        // A surface omitting a Commerce route must not accept the extra route.
        writeFileSync(surfacePath, JSON.stringify({ ...surface, domain_route_count: 3, route_names: names.slice(1) }));
        expect(run().exitCode).toBe(1);
        writeFileSync(surfacePath, JSON.stringify(surface));
        writeFileSync(routesPath, JSON.stringify(names.slice(1).map(name => ({ name }))));
        const missing = run();
        expect(missing.exitCode).toBe(1);
        expect(missing.stderr.toString()).toContain("commerce.catalog.index");
        // An additional descriptor entry cannot silently escape pin verification.
        domains.operation.ref = "a".repeat(40);
        writeFileSync(registryPath, JSON.stringify({ domains }));
        expect(run().stderr.toString()).toContain("not the pinned");
    } finally {
        rmSync(fixture, { recursive: true, force: true });
    }
});
