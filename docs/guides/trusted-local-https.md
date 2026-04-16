# Trusted Local HTTPS (No "Not Secure" Warning)

When developing locally, browsers show a **"Not Secure"** warning if the TLS certificate is not trusted by the system. This guide explains how Belimbing provisions trusted HTTPS for local development and how to fix the warning if it appears.

## How It Works

Belimbing uses two layers for local HTTPS:

| Layer | Role |
|-------|------|
| **mkcert** | Generates locally-trusted TLS certificates signed by a root CA installed in your system trust store. Browsers trust these certificates without warnings. |
| **`tls internal`** (Caddy fallback) | When mkcert certificates are absent, Caddy generates its own self-signed certificate from an internal CA. Browsers **do not** trust this CA by default, causing the "Not Secure" warning. |

The preferred path is **mkcert**. The `tls internal` fallback exists only for first-run bootstrapping or environments where mkcert is unavailable.

## Prerequisites

- **mkcert** — install it from [github.com/FiloSottile/mkcert](https://github.com/FiloSottile/mkcert)

  ```bash
  # Ubuntu/Debian
  sudo apt install -y libnss3-tools
  sudo apt install -y mkcert   # or install from GitHub releases

  # macOS
  brew install mkcert
  ```

- Run `mkcert -install` once to create and trust the local root CA:

  ```bash
  mkcert -install
  ```

## Quick Fix

If you already ran setup but the browser shows "Not Secure", regenerate the mkcert certificates and reload:

```bash
# 1. Generate trusted certificates for your domains
mkcert -cert-file certs/local.blb.lara.pem \
       -key-file certs/local.blb.lara-key.pem \
       local.blb.lara local.api.blb.lara

# 2. Re-run the ingress setup to install them into system Caddy
./scripts/setup-steps/72-caddy-ingress.sh local

# 3. Restart the app
./scripts/stop-app.sh
./scripts/start-app.sh
```

## Setup Flow (Normal Path)

During a full setup (`./scripts/setup.sh`), the following steps handle HTTPS automatically:

1. **Step 70 — Domains & TLS** (`70-domains.sh`): Configures domains (`local.blb.lara`, `local.api.blb.lara`), adds `/etc/hosts` entries, and generates mkcert certificates into `certs/`.

2. **Step 72 — Ingress Mode** (`72-caddy-ingress.sh`): Configures how traffic reaches BLB:
   - **Shared** (recommended): System Caddy owns `:443` and proxies to BLB on an internal port. The setup copies mkcert certs into a system-readable location (`/etc/caddy/blb/certs/`) so the `caddy` service user can read them.
   - **Direct**: BLB's embedded FrankenPHP serves HTTPS itself on `:443`.

3. **Step 75 — SSL Trust** (`75-ssl-trust.sh`): Only needed when falling back to `tls internal`. Installs Caddy's internal CA into the system trust store. Not needed when mkcert certificates are present.

## Ingress Modes and TLS

### Shared Ingress (Recommended)

System Caddy runs as a system service (user `caddy`) and terminates TLS on `:443`. BLB's FrankenPHP listens on a local HTTP port (default `8000`).

```
Browser ──HTTPS:443──▶ System Caddy ──HTTP:8000──▶ FrankenPHP (BLB)
```

The TLS certificate is configured in the system Caddy site block at `/etc/caddy/blb/<domain>.caddy`. The ingress setup copies project mkcert certs to `/etc/caddy/blb/certs/` because the `caddy` service user typically cannot traverse the developer's home directory.

### Direct Ingress

FrankenPHP binds `:443` itself and reads certs directly from the project `certs/` directory. No system Caddy is involved.

```
Browser ──HTTPS:443──▶ FrankenPHP (BLB)
```

## Troubleshooting

### "Not Secure" warning in the browser

| Cause | Fix |
|-------|-----|
| mkcert not installed or root CA not trusted | Run `mkcert -install`, then regenerate certs (see [Quick Fix](#quick-fix)) |
| Certificates missing from `certs/` | Run `./scripts/setup-steps/70-domains.sh local` to regenerate |
| System Caddy using `tls internal` instead of mkcert certs | Re-run `./scripts/setup-steps/72-caddy-ingress.sh local` — it detects mkcert certs and provisions them for system Caddy |
| System Caddy can't read cert files (permission denied) | The ingress step copies certs to `/etc/caddy/blb/certs/`; re-run `72-caddy-ingress.sh` or copy manually with `sudo` |

### Verifying the certificate

```bash
# Check which certificate the server presents
echo | openssl s_client -connect local.blb.lara:443 -servername local.blb.lara 2>/dev/null \
     | openssl x509 -noout -issuer -dates

# A mkcert cert shows an issuer like: "mkcert <user>@<host>"
# A tls-internal cert shows: "Caddy Local Authority"
```

### Checking the installed site block

```bash
cat /etc/caddy/blb/local.blb.lara.caddy
```

A correct site block with mkcert looks like:

```caddyfile
local.blb.lara {
    tls /etc/caddy/blb/certs/local.blb.lara.pem /etc/caddy/blb/certs/local.blb.lara-key.pem
    reverse_proxy 127.0.0.1:8000
}
```

If it shows `tls internal` instead, re-run the ingress step.

### Reloading system Caddy after manual changes

```bash
caddy validate --config /etc/caddy/Caddyfile
caddy reload --config /etc/caddy/Caddyfile
```

## File Locations

| File | Purpose |
|------|---------|
| `certs/<domain>.pem` | mkcert certificate (project-local) |
| `certs/<domain>-key.pem` | mkcert private key (project-local) |
| `/etc/caddy/blb/certs/` | System-readable copy of certs for the `caddy` service user |
| `/etc/caddy/blb/<domain>.caddy` | System Caddy site block for BLB |
| `.caddy/system/<domain>.caddy` | Local copy of the generated site fragment |

## Related

- [Quick Start Guide](quickstart.md) — full setup walkthrough
- [Development Setup](development-setup.md) — Caddy and FrankenPHP architecture
- [Troubleshooting](troubleshooting.md) — general troubleshooting
