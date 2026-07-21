# Vite Asset Builds And Optional Hot Reload

**Document Type:** Tutorial
**Purpose:** Explain BLB's Vite workflow and the licensee-controlled reload setting
**Last Updated:** 2026-07-21

Vite builds BLB's CSS and JavaScript from `resources/app.css` and
`resources/core/js/app.js`. Laravel's `@vite` directive reads the generated
`public/build/manifest.json` in ordinary operation.

## Default: manual browser refresh

Hot reload is off by default. Vite can still serve local source assets, but a
source edit does not change an open browser tab until the user explicitly
refreshes it. This prevents unexpected interruption of a form or other work in
progress.

Refresh the browser when you are ready to see a frontend change. Run
`bun run build` to generate the static assets used by deployment; the Updates
page uses that same build path.

## Licensee opt-in

The licensee can opt in for a specific installation by setting this in its
`.env` file:

```dotenv
VITE_HOT_RELOAD=true
```

With the normal local launcher running, Vite then enables HMR for CSS and
JavaScript and Laravel's Vite plugin refreshes the configured Core, module,
and extension Blade paths. Caddy proxies the Vite development routes and
WebSocket only inside the local development topology.

Set `VITE_HOT_RELOAD=false` (or remove the setting) to return to manual
browser refreshes. Restart the local launcher after changing the
setting because Vite reads it when it starts.

## Boundaries

- `vite.config.js` owns the opt-in decision and Blade refresh paths.
- `.env.example` documents the setting; each licensee's untracked `.env`
  decides its value.
- `VITE_PORT` remains the local Vite server port when source serving is used;
  it is not a public application port.
- Production serves built assets from `public/build`; it does not use the Vite
  development server.
