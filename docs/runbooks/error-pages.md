# Branded Error And Maintenance Pages

**Document Type:** Runbook
**Scope:** What a visitor sees when Belimbing cannot answer normally, and who serves it
**Last Updated:** 2026-09-24

## Why Three Layers

During a production server switch-over on 2026-09-24 a Belimbing site was down for about three minutes and visitors saw Cloudflare's generic "Bad gateway, Error code 502" page. No single component can cover every failure: the application cannot render anything once PHP has crashed, and the web server cannot answer once the machine is gone. Belimbing therefore keeps one branded page at each layer, all rendered from the same Blade shell so they cannot drift apart.

| Layer | Answers when | Page | Served by |
|-------|--------------|------|-----------|
| 1. Application | Laravel is running: `500`, maintenance `503`, `403`, `404`, `419` | `resources/core/views/errors/*.blade.php` on `errors/layout.blade.php` | Laravel's exception handler |
| 2. Web server | Caddy raises a 5xx, the instance is unreachable from the shared ingress, or PHP answers a raw 5xx with no page | `public/errors/5xx.html` | Caddy: the instance `Caddyfile`, and the system ingress block in shared mode |
| 3. CDN | The whole server is unreachable | `public/errors/cloudflare-5xx.html` | Cloudflare custom 5xx page |

The seam between the layers is `App\Base\Foundation\Services\ErrorPages`: it renders the static pages and defines the response header (`X-Belimbing-Error-Page: app`) that marks an error page the application rendered itself.

## Layer 1: Application Pages

The standalone shell (`resources/core/views/errors/layout.blade.php`) is deliberately self-sufficient: inline CSS, the favicon inlined as the logo, no Vite, no Livewire, no session, no database. A 500 or a maintenance 503 is exactly the moment those things may be what broke. `tests/Feature/Base/Foundation/ErrorPagesTest.php` renders both with the database unreachable and no session to keep it that way.

Manual maintenance is plain Laravel:

```bash
php artisan down --retry=15   # branded "Down for maintenance", page retries by itself
php artisan up
```

The in-app updater (`DeploymentMaintenanceGuard`) enters maintenance itself and the page then says "Installing an update". Production keeps `APP_MAINTENANCE_DRIVER=file`, so the maintenance check never needs the database.

Every 5xx the application renders, HTML or JSON, carries `X-Belimbing-Error-Page: app`. Layer 2 uses that mark to leave application pages alone.

## Layer 2: Web Server Fallback

`public/errors/5xx.html` is a committed artifact rendered from the Blade shell. Regenerate it whenever the shell, the favicon, or the application name changes:

```bash
php artisan blb:error-pages:publish          # writes both static pages under public/errors
php artisan blb:error-pages:publish --check  # exits 1 when a committed page is stale
```

`tests/Feature/Base/Foundation/StaticErrorPagesTest.php` fails when the committed pages no longer match the shell, so a restyle cannot ship without them. Commit the regenerated files with the change that caused them.

### Instance Caddyfile (every mode)

The project `Caddyfile` and `Caddyfile.orb` carry a `handle_errors 5xx` block that serves the page with the original status code. It fires only for 5xx errors Caddy itself raises for the site. When FrankenPHP's embedded PHP hangs, fails to start, or returns its own plain-text overload 503, Caddy sees no error, so this block does not fire: an instance-direct deployment then falls through to the CDN (Cloudflare) page, while the shared-ingress topology below serves the branded page. A response PHP produced, including Laravel's own error pages, is not a Caddy error and is never replaced here. A fatal before Laravel boots on the non-worker path is covered by `public/index.php`, which answers with the same static page instead of leaking the message.

### System ingress block (shared mode)

In the default shared-ingress topology (`docs/architecture/caddy-frankenphp-topology.md`) the system Caddy proxies the public hostname to the instance. Its site block, rendered by `caddy_render_system_site_block` in `scripts/shared/caddy.sh` and installed by `scripts/setup-steps/72-caddy-ingress.sh`, covers two more cases:

- **Instance unreachable** (restarting, mid-update, crashed): `handle_errors` serves the page as a 502 or 504.
- **Raw 5xx from PHP** (a fatal with an empty body): `reverse_proxy` `handle_response` swaps it for the page, keeping the status. Responses marked `X-Belimbing-Error-Page: app` pass through untouched, so maintenance and 500 copy survive the proxy.

Setup copies `public/errors/5xx.html` to `<system Caddy dir>/blb/errors/5xx.html` (next to the provisioned certs) so the caddy service user can read it even while the project tree is unreadable or being swapped. After regenerating the page, re-run the ingress step to refresh that copy:

```bash
scripts/setup-steps/72-caddy-ingress.sh production
```

### Verifying

```bash
# Instance down: expect 502 and the branded title
scripts/stop-app.sh && curl -sS -o /dev/null -w '%{http_code}\n' https://example.test/
curl -sS https://example.test/ | grep -o '<title>[^<]*</title>'

# Maintenance: expect 503 and "Down for maintenance"
php artisan down && curl -sS -D - https://example.test/ | grep -i 'HTTP/\|X-Belimbing-Error-Page'
php artisan up
```

## Layer 3: Cloudflare Custom 5xx Page

When the server itself is unreachable only the CDN can answer. `public/errors/cloudflare-5xx.html` is the same page plus Cloudflare's mandatory `::CLOUDFLARE_ERROR_500S_BOX::` token, which Cloudflare replaces with its own diagnostics (Ray ID, error text) when it serves the page. It is a single self-contained HTML file: inline CSS, inline logo, no scripts, no external requests, a few kilobytes.

To point Cloudflare at it (nothing in this repository changes any Cloudflare setting):

1. Make the page reachable over HTTPS. The simplest source is the running site itself: `https://<your-domain>/errors/cloudflare-5xx.html`. Any static host works too, since the file has no dependencies.
2. In the Cloudflare dashboard open the zone, then **Error Pages** (listed under *Custom Pages* on some plans), choose **500 Class Errors**, and enter that URL as the custom page.
3. **Publish**. Cloudflare fetches the page once and stores its own copy, so the source must be reachable at publish time and must not require a login.
4. Preview from the same screen. Cloudflare then shows this page for the 5xx errors it generates itself, including when it cannot reach the origin.

Repeat the publish step after regenerating the page. Cloudflare keeps the copy it fetched; updating the file on the server does not update Cloudflare.

`robots.txt` disallows `/errors/`, and every page carries `noindex`, so the fallback pages never appear in search results.

## Follow-Up

- The SBG fork's version-swap updater should put the site into maintenance mode (`php artisan down`) before a swap and lift it afterwards, so visitors see the layer 1 page instead of the layer 2 or 3 page while the swap runs. That change lives in the fork's updater, not upstream.
