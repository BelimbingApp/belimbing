# Hosting Belimbing in production

**Document type:** operator guide
**Last updated:** 2026-09-10
**Related:** `docs/guides/quickstart.md`, `Caddyfile`, `scripts/setup.sh`, `scripts/update.sh`

This guide is for a small first deployment: one server, a handful of real
users, and no dedicated operations team. It explains what to buy, why, and
what the FrankenPHP runtime changes about a normal Laravel deployment.

The worked example is a Malaysian deployment with about 20 users. The
reasoning transfers to other small deployments; the specific provider and
region do not.

## What you are running

Belimbing runs on **FrankenPHP through Laravel Octane**, in worker mode.
That is different from a traditional Laravel server, and it makes the stack
smaller:

- There is **no php-fpm**.
- There is **no separate web server**. Caddy is built into FrankenPHP, so
  the same process handles HTTPS, static files, and PHP.
- The framework **boots once and stays in memory**, instead of booting on
  every request.

So a full production server is only three things:

| Part | What it does |
|------|--------------|
| FrankenPHP / Octane | HTTPS, static files, and the application |
| PostgreSQL | The database |
| `php artisan queue:work` | Background jobs (`QUEUE_CONNECTION=database`) |

Nothing else needs to run. In particular you do not need Redis at this size,
and you must not put nginx or Apache in front.

### What worker mode costs you

Because the framework stays in memory, state can leak from one request to the
next. Two settings in `.env.example` control this — uncomment both:

```
OCTANE_WORKERS=4
OCTANE_MAX_REQUESTS=500
```

`OCTANE_MAX_REQUESTS` restarts each worker after 500 requests, so anything
that leaks is thrown away regularly instead of building up for days.

## Choosing a server

### Size

For roughly 20 users, **2 vCPU and 4 GB of RAM** is comfortable:

- 4 Octane workers: about 400–600 MB
- PostgreSQL: about 200 MB
- Queue worker: about 100 MB

The rest is headroom. That headroom matters because the `Caddyfile` allows
12 photo uploads of 10 MB each in a single request, so memory use is spiky
rather than flat. A 2 GB server will technically run, but leaves no room for
that spike. Buy the 4 GB.

### Region

Pick the region by **distance to your users**, not by price.

Belimbing's interface is Livewire, which sends many small requests during
normal use. Every one of them pays the network round trip, so latency is felt
as sluggishness on every click — not just on the first page load.

For users in West Malaysia:

| Server location | Round trip | Verdict |
|-----------------|-----------|---------|
| Kuala Lumpur / Cyberjaya | 5–25 ms | Best |
| Singapore | 10–25 ms | Fine |
| Europe / US | 160–250 ms | Noticeably slow |

### Hosting in the same country as your users

Hosting locally has two real advantages beyond speed:

1. **Data residency.** Malaysia's PDPA restricts moving personal data out of
   the country. Keeping the server in Malaysia removes that question instead
   of forcing you to answer it.
2. **It is something you can tell customers.**

The costs are also real:

- Local providers usually charge more per GB of RAM than the large
  international ones.
- Their control panels are older and often have no usable API, so rebuilding
  a server is a manual job rather than a scripted one.
- Support tends to be by ticket, and slower.

Because rebuilding is manual, your own backups and a written runbook matter
more here than they would on a large cloud provider.

### A concrete choice

For the Malaysian example, **Exabytes NVMe C2** — 2 vCPU, 4 GB RAM, 100 GB
NVMe, 4 TB transfer, around RM 60 per month — fits the sizing above. It is
KVM, so it is a real virtual machine; avoid any OpenVZ plan, because
FrankenPHP's process model is happier with its own kernel. Exabytes NVMe C3
(8 GB, about RM 76) is the upgrade path if you outgrow it.

Two things to confirm with any local provider before paying, because pricing
pages rarely state them:

- **Where the datacenter actually is.** "Tier-3 datacenter" is not a
  location. If the answer is Singapore, the data-residency reason for going
  local disappears, and a large international provider becomes competitive
  again.
- **Whether the network port speed is dedicated or shared.**

Skip managed-service add-ons. They often cost more than the server, and you
are deploying with your own scripts.

## Production configuration

All of these are environment variables. Do not edit the `Caddyfile`.

| Variable | Production value | Why |
|----------|-----------------|-----|
| `CADDY_BIND_ADDRESS` | `0.0.0.0` | Development binds to loopback only |
| `HTTPS_PORT` | `443` | Development uses a high port |
| `TLS_DIRECTIVE` | *(empty)* | Empty means Caddy gets a free Let's Encrypt certificate automatically |
| `CADDY_VITE_SNIPPET` | `scripts/caddy-snippets/vite-disabled.caddy` | Vite is a development-only process |
| `APP_DOMAIN` | your real domain | |

The `Caddyfile` has **one site block**, so you need **one DNS record**, for
example `app.example.my`, pointing to the server IP address.

### HTTPS certificates

FrankenPHP requests and renews its own certificate from Let's Encrypt. You do
not install one, and you do not need a CDN to provide one.

For this to work, ports 80 and 443 must both be reachable from the internet.
Port 80 is used for the certificate challenge even though the site itself
runs on 443.

### Do not put a proxy in front

If you place a CDN or proxy (such as Cloudflare's orange-cloud mode) in front
of the server, Laravel will see the proxy's IP address as the client IP for
every request. That breaks rate limiting, audit logs, and anything else that
depends on knowing who is connecting.

If you ever do add one, you must set `TRUSTED_PROXIES` to that provider's IP
ranges. The `TrustedProxiesTest` covers this behaviour. The simplest correct
setup for a small deployment is no proxy at all: DNS points straight at the
server.

## Deploying

The repository already contains the scripts you need. Deployment is mostly
running the existing setup on a server instead of a laptop:

| Task | Command |
|------|---------|
| First install | `./scripts/setup.sh` |
| Start | `./scripts/start-app.sh` |
| Stop | `./scripts/stop-app.sh` |
| Update to a new version | `./scripts/update.sh` |

See `docs/guides/quickstart.md` for what `setup.sh` does, and
`docs/guides/adopter-fork.md` if you are deploying from a fork rather than
from the framework's own branch.

Two processes must survive a reboot and restart if they crash. Run each under
a systemd unit:

- FrankenPHP / Octane
- `php artisan queue:work`

## Backups

**Do not rely on your provider's backups alone.** Provider backups are often
weekly, which means you could lose up to seven days of customer data. Treat
them as a last resort, not as your plan.

Run your own `pg_dump` nightly and copy the result **off the server**, to
object storage such as Backblaze B2. A backup stored on the same machine does
not protect you from the failure that is most likely to happen: losing the
machine.

Then restore one backup, once, onto a scratch server. A backup you have never
restored is not yet known to work.

## Sending email

A new server cannot send reliable email. Mail sent directly from a fresh IP
address is usually treated as spam, so password resets and invitations will
silently fail to arrive.

Use a mail provider such as Postmark or Resend. Free tiers cover a deployment
of this size. Add their SPF and DKIM records to your DNS.

## Domain names

Whoever you register with, the failure that actually happens is a lapsed
renewal after a payment method expires — the registrar's warning emails get
filtered or ignored, and the domain is suspended. Put the renewal date in
your own calendar, independent of the registrar's reminders, and check that
the card on file is current.

If you register a Malaysian domain, note that `.com.my` requires a registered
business (SSM), while `.my` is open to individuals.

## Summary checklist

- [ ] 2 vCPU / 4 GB KVM server, in the same region as your users
- [ ] Datacenter location confirmed in writing
- [ ] Two DNS records (app and API) pointing at the server IP
- [ ] Ports 80 and 443 open
- [ ] Production environment variables set (table above)
- [ ] `OCTANE_WORKERS` and `OCTANE_MAX_REQUESTS` set
- [ ] systemd units for Octane and `queue:work`
- [ ] Nightly `pg_dump` copied off the server
- [ ] One backup restored successfully as a test
- [ ] Mail provider configured, with SPF and DKIM records
- [ ] Domain renewal date in your own calendar
