# Caddyfile Guide for Belimbing

<!-- SPDX-License-Identifier: AGPL-3.0-only -->
<!-- Copyright (c) 2025 Ng Kiat Siong -->

**Document Type:** Tutorial
**Purpose:** Explain Caddy and Caddyfile configuration in Belimbing
**Related:** [Development Setup Guide](../guides/development-setup.md), [Vite's Roles in Development Architecture](./vite-roles.md)
**Last Updated:** 2025-12-09

---

## Overview

Caddy is a powerful, production-ready web server with automatic HTTPS that serves as Belimbing's reverse proxy. This guide explains Caddy basics and how to configure Caddyfiles for your Belimbing projects.

---

## What is Caddy?

Caddy is a modern web server and reverse proxy written in Go. It's designed to be simple, secure, and performant.

### Key Features

- **Automatic HTTPS:** Caddy automatically obtains and renews SSL/TLS certificates from Let's Encrypt
- **Zero Configuration:** Works out of the box with sensible defaults
- **Reverse Proxy:** Efficiently routes requests to backend services
- **HTTP/2 and HTTP/3:** Modern protocol support
- **Easy Configuration:** Simple, human-readable Caddyfile syntax

### Why Caddy for Development?

- **HTTPS by Default:** Modern web features (Service Workers, WebAuthn, etc.) require HTTPS
- **No Manual Certificate Management:** Automatic certificate generation for local development
- **Simple Configuration:** Easy to understand and modify
- **Fast:** Low latency and high performance

---

## Caddyfile Basics

A Caddyfile is Caddy's configuration file. It uses a simple, declarative syntax.

### Basic Structure

```
site_address {
    directive
    directive value
}
```

### Site Address Format

```
scheme://hostname:port
```

Examples:
- `https://example.com` - HTTPS on port 443
- `http://localhost:8080` - HTTP on port 8080
- `https://example.com` - HTTPS (default)

---

## Belimbing's Caddyfile Structure

Belimbing uses Caddyfiles in different contexts:

### 1. The Main Caddyfile

Located in the project root (`$PROJECT_ROOT/Caddyfile`), this single file handles all environments using Caddy's environment variable syntax (`{$VAR}`).

```caddyfile
{
    # Global options
}

{$APP_DOMAIN} {
    # TLS Configuration
    # - Local: "internal" for self-signed
    # - Prod: Email address for Let's Encrypt
    tls {$TLS_MODE}

    # Logging
    log {
        output file .caddy/logs/access.log
        format console
    }

    # Vite / Frontend Config
    handle /build/* {
        reverse_proxy 127.0.0.1:{$VITE_PORT} {
            header_up Host {host}
            header_up X-Real-IP {remote_host}
            header_up X-Forwarded-Proto {scheme}
        }
    }

    handle /assets/* {
        reverse_proxy 127.0.0.1:{$VITE_PORT} {
            header_up Host {host}
            header_up X-Real-IP {remote_host}
            header_up X-Forwarded-Proto {scheme}
        }
    }

    # Laravel Backend
    reverse_proxy 127.0.0.1:{$APP_PORT} {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Port {server_port}
    }
}
```


---

## Key Directives Explained

### `tls`

Configures TLS/SSL certificates.

**Development (automatic):**
```caddyfile
tls internal
```
Uses Caddy's internal CA for automatic local certificates.

**Production (manual certificates):**
```caddyfile
tls certs/domain.pem certs/domain-key.pem
```
Uses provided certificate files.

### `reverse_proxy`

Forwards requests to a backend server.

**Basic usage:**
```caddyfile
reverse_proxy http://127.0.0.1:8000
```

**With headers:**
```caddyfile
reverse_proxy http://127.0.0.1:8000 {
    header_up Host {host}
    header_up X-Real-IP {remote_host}
    header_up X-Forwarded-Proto {scheme}
    header_up X-Forwarded-Port {server_port}
}
```

**Header explanations:**
- `Host {host}` - Preserves the original host header
- `X-Real-IP {remote_host}` - Passes the client's real IP address
- `X-Forwarded-Proto {scheme}` - Indicates the original protocol (http/https)
- `X-Forwarded-Port {server_port}` - Indicates the original port

### `handle`

Routes requests based on path patterns. Handles are processed in order.

```caddyfile
handle /build/* {
    reverse_proxy http://127.0.0.1:5173
}

handle /assets/* {
    reverse_proxy http://127.0.0.1:5173
}
```

**Path matching:**
- `/build/*` - Matches `/build/` and all paths under it
- `/assets/*` - Matches `/assets/` and all paths under it

### `log`

Configures request logging.

```caddyfile
log {
    output file .caddy/logs/local-access.log
    format console
}
```

**Options:**
- `output file <path>` - Write logs to a file
- `format console` - Human-readable format

### Global Options Block

```caddyfile
{
    auto_https off
    admin unix//tmp/caddy-blb-local-$$.sock
}
```

**Options:**
- `auto_https off` - Disables automatic HTTPS (useful when using manual certificates)
- `admin <address>` - Caddy's admin API endpoint

---

## Request Flow in Belimbing

### Development Request Flow

```
Browser Request: https://local.blb.lara/build/app.js
    ↓
Caddy receives HTTPS request
    ↓
Caddy matches /build/* handle
    ↓
Caddy forwards to Vite: http://127.0.0.1:${VITE_PORT}/build/app.js
    ↓
Vite compiles and serves the file
    ↓
Caddy returns compiled JavaScript to browser
```

### Laravel Request Flow

```
Browser Request: https://local.blb.lara/api/users
    ↓
Caddy receives HTTPS request
    ↓
Caddy doesn't match /build/* or /assets/* handles
    ↓
Caddy forwards to Laravel: http://127.0.0.1:${APP_PORT}/api/users
    ↓
Laravel processes request and returns response
    ↓
Caddy returns response to browser
```

In normal local development, `start-app.sh` resolves `${APP_PORT}` and `${VITE_PORT}` at runtime, then writes the concrete values to `storage/app/.devops/ports.env` before Caddy reloads.

---

## Common Configuration Patterns

### Multiple Domains

```caddyfile
frontend.blb.lara {
    reverse_proxy http://127.0.0.1:{$VITE_PORT}
}

api.blb.lara {
    reverse_proxy http://127.0.0.1:{$APP_PORT}
}
```

### Default HTTPS (443)

```caddyfile
https://local.blb.lara {
    tls certs/local.blb.lara.pem certs/local.blb.lara-key.pem
    reverse_proxy http://127.0.0.1:{$APP_PORT}
}
```

### Path-Based Routing

```caddyfile
local.blb.lara {
    handle /api/* {
        reverse_proxy http://127.0.0.1:{$APP_PORT}
    }

    handle /admin/* {
        reverse_proxy http://127.0.0.1:{$APP_PORT}
    }

    handle {
        reverse_proxy http://127.0.0.1:{$VITE_PORT}
    }
}
```

### Static File Serving

```caddyfile
local.blb.lara {
    handle /static/* {
        file_server {
            root /path/to/static/files
        }
    }

    handle {
        reverse_proxy http://127.0.0.1:{$APP_PORT}
    }
}
```

---

## Caddyfile Locations in Belimbing

Belimbing checks for Caddyfiles in this order:

1. **`/etc/caddy/Caddyfile`** - System-wide (Linux)
   - Detected but never modified
   - Belimbing runs a project-specific Caddy using the repo-root `Caddyfile`

2. **`$HOME/.config/caddy/Caddyfile`** - User-level
   - Can be updated if Belimbing config exists

3. **`$PROJECT_ROOT/Caddyfile`** - Project-specific
   - Can be updated when domains change

4. **`/usr/local/etc/caddy/Caddyfile`** - System-wide (macOS/Homebrew)
   - Detected but never modified
   - Belimbing runs a project-specific Caddy using the repo-root `Caddyfile`

### Belimbing Caddyfile

This is the primary configuration file located at `$PROJECT_ROOT/Caddyfile`.


**Configuration Philosophy: Environment Parity**

Belimbing follows the "Environment Parity" principle: the configuration used in local development should match production as closely as possible.

1.  **Single Source of Truth**: There is only one `Caddyfile`. It is committed to Git and used in all environments.
2.  **Environment Variables**: Differences between environments (domain names, ports, TLS modes) are handled via environment variables (`{$VAR}`).
3.  **Reproducibility**: Because the config is identical, bugs found in production can be easily reproduced locally by simply adjusting the environment variables.

**Performance & Stability:**

-   **Zero-Generation Overhead**: No files are created at runtime.
-   **Immutable Infrastructure**: The config file is static and version-controlled, reducing the risk of "magic" runtime errors.

**Comparison:**

| Approach | Local Dev | Production |
| :--- | :--- | :--- |
| **Old (Generated)** | Fast, but different config structure | Different config, hard to debug matches |
| **New (Static)** | Uses `Caddyfile` with `{$APP_DOMAIN}=local` | Uses same `Caddyfile` with `{$APP_DOMAIN}=prod.com` |

**Location:** `$PROJECT_ROOT/Caddyfile`

---

## Testing Caddyfile Configuration

### Validate Syntax

```bash
caddy validate --config Caddyfile
```

### Test Configuration

```bash
caddy run --config Caddyfile --adapter caddyfile
```

### Dry Run

```bash
caddy adapt --config Caddyfile
```

---

## Troubleshooting

### Certificate Issues

If you see certificate errors:

1. **Check certificate files exist:**
   ```bash
   ls -la certs/
   ```

2. **Regenerate certificates:**
   ```bash
   mkcert -install
   mkcert local.blb.lara local.api.blb.lara
   ```

### Port Conflicts

If ports are in use:

1. **Check what's using the port:**
   ```bash
   sudo lsof -i :443
   ```

2. **Free up port 443:**
   Stop the process using 443 (or disable the conflicting proxy), then retry.

### Reverse Proxy Not Working

1. **Check backend is running:**
   ```bash
   curl http://127.0.0.1:8000
   ```

2. **Verify headers are passed:**
   Check Laravel logs for `X-Forwarded-*` headers

3. **Check Caddy logs:**
   ```bash
   tail -f .caddy/logs/local-access.log
   ```

---

## Best Practices

1. **Use `handle` for path-based routing** - More explicit than relying on order
2. **Always pass forwarding headers** - Ensures Laravel knows the original request details
3. **Use project-specific Caddyfiles** - Avoid modifying system files
4. **Keep certificates in `certs/` directory** - Organized and easy to manage
5. **Use environment-specific files** - Separate configs for local, staging, production

---

## Additional Resources

- [Caddy Documentation](https://caddyserver.com/docs/)
- [Caddyfile Syntax](https://caddyserver.com/docs/caddyfile)
- [Reverse Proxy Directives](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy)
- [TLS Configuration](https://caddyserver.com/docs/caddyfile/directives/tls)

---

## Summary

Caddy provides automatic HTTPS and efficient reverse proxying for Belimbing. The Caddyfile syntax is simple and declarative, making it easy to configure routing, certificates, and logging. Belimbing automatically manages Caddyfiles for different environments while allowing manual customization when needed.
