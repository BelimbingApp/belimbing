# Caddy and FrankenPHP Development Guide

**Document Type:** Architecture Specification
**Purpose:** Define a simplified development setup using Caddy for custom domain-based local development
**Goal:** Simplify developer experience - users only need to run Caddy, everything else auto-starts with hot reloading
**Last Updated:** 2025-12-10

---

## Overview

This architecture enables developers to use friendly domain names (`local.blb.lara`, `stage.blb.lara`) instead of IP addresses and ports, with automatic SSL certificates and hot reloading. Native setup now defaults to `shared` ingress, where setup provisions or reuses a system Caddy daemon that owns `:80` and `:443` and proxies to BLB's local FrankenPHP listener. `direct` mode remains available as a single-instance fallback when a system ingress daemon is not desired.

## Configuration Philosophy: Environment Parity

Belimbing adheres to the **Environment Parity** principle. The goal is to maximize similarity between development, staging, and production environments to reduce "works on my machine" issues.

To achieve this:
1.  **Single Caddyfile**: We use **one** `Caddyfile` for ALL environments.
2.  **Configuration-as-Code**: This file is version-controlled in the repository root.
3.  **Variable Injection**: Differences (domains, ports, TLS modes) are injected via environment variables (`APP_DOMAIN`, `TLS_MODE`, etc.) at runtime.

### Key Benefits

- **Simple URLs**: `local.blb.lara` instead of `127.0.0.1:8000`
- **Auto SSL**: Caddy automatically provisions SSL certificates for `.blb` domains
- **Zero Configuration**: Services auto-start when Caddy starts
- **Hot Reload**: File changes automatically trigger browser refresh (CSS/JS/Blade)
- **Multi-Environment**: Separate repos for dev and staging, different configurations
- **Production Ready**: Same architecture supports production deployments

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     Developer's Browser                      │
│                  https://local.blb.lara                        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Caddy Reverse Proxy                       │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Route: /         → Laravel PHP Server (port 8000)  │  │
│  │  Route: /build/*  → Vite Dev Server (port 5173)     │  │
│  │  Route: /assets/* → Vite Dev Server (port 5173)     │  │
│  └──────────────────────────────────────────────────────┘  │
│  Auto SSL for .blb domains                                  │
└───────┬──────────────────────────────────────┬──────────────┘
        │                                      │
        ▼                                      ▼
┌──────────────────────┐          ┌──────────────────────────┐
│  Laravel Application │          │    Vite Dev Server       │
│  php artisan serve   │          │    npm run dev           │
│  Port: 8000          │          │    Port: 5173            │
│                      │          │                          │
│  • Watches: PHP/     │          │  • Watches: CSS/JS       │
│    Blade files       │          │  • Hot Module Reload     │
│  • Queue Worker      │          │  • WebSocket for HMR     │
│  • Log Watcher       │          │                          │
└──────────────────────┘          └──────────────────────────┘
```

---

## Vite's Roles in This Architecture

Vite plays several critical roles in the development workflow. For a detailed explanation of how Vite integrates with this architecture, see:

**[Understanding Vite's Roles](../tutorials/vite-roles.md)** - Comprehensive guide to Vite's functions in Laravel development

This tutorial covers:
- Development asset server functionality
- CSS and JavaScript compilation process
- Hot Module Replacement (HMR) mechanism
- Blade template refresh triggers
- Asset path resolution
- WebSocket communication
- Production build capabilities

---

## Directory Structure

```
blb/
├── Caddyfile                 # Single Source of Truth Caddy config
├── scripts/
│   ├── start-app.sh          # Auto-start script (exports env vars)
│   └── stop-app.sh           # Stop services
├── config/
│   └── caddy/
│       └── (Removed - moved to root Caddyfile)
└── .caddy/                   # Caddy data directory (git-ignored)
    ├── certs/                # SSL certificates
    └── logs/                 # Caddy access logs
```

---

## Implementation Plan

### Phase 1: Caddy Configuration

#### 1.1 The Unified Caddyfile

**Location:** `$PROJECT_ROOT/Caddyfile`

We use a single file that adapts via environment variables:

```caddyfile
{
    # Global options
}

{$APP_DOMAIN} {
    # TLS Configuration
    # - Local: "internal"
    # - Prod: Email address
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

**Key Points:**
- `start-app.sh` auto-assigns free internal Laravel, Vite, and Reverb ports when they are not pinned in `.env`
- Host-based routing keeps external URLs stable while internal ports remain runtime-managed
- Automatic HTTPS with `tls internal` for `.blb` domains
- Proper proxy headers for Laravel to detect HTTPS and real client IP
- Separate log files for each environment

#### 1.2 Vite Configuration Updates

**Location:** `vite.config.js`

Update to support different ports and proper host configuration:

```javascript
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/app.css', 'resources/core/js/app.js'],
            refresh: [
                'resources/core/views/**',
                'resources/core/css/**',
                'resources/core/js/**',
            ],
        }),
        tailwindcss(),
    ],
    server: {
        host: '127.0.0.1',
        port: parseInt(process.env.VITE_PORT || '5173'),
        strictPort: true,
        hmr: {
            host: 'dev.lara.blb',
            protocol: 'wss',
            clientPort: 443,
        },
        cors: true,
    },
});
```

**Runtime port behavior:**
- `VITE_PORT`, `APP_PORT`, and `REVERB_SERVER_PORT` may be pinned for explicit overrides
- When unset, `./scripts/start-app.sh` chooses free ports automatically and writes them to `storage/app/.devops/ports.env`

### Phase 2: Service Orchestration

#### 2.1 Auto-Start Script for Development

**Location:** `scripts/start-dev.sh`

```bash
#!/bin/bash

# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) 2025 Ng Kiat Siong

set -e

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

echo -e "${GREEN}Starting BLB Development Environment...${NC}"

# Check if Caddy is installed
if ! command -v caddy &> /dev/null; then
    echo -e "${YELLOW}Caddy is not installed. Please install it first.${NC}"
    echo "Visit: https://caddyserver.com/docs/install"
    exit 1
fi

# Set environment variables
export APP_ENV=local

# Resolve free runtime ports when overrides are not pinned in .env
APP_PORT=$(next_free_port 8000)
VITE_PORT=$(next_free_port 5173)
REVERB_SERVER_PORT=$(next_free_port 8080)
export APP_PORT VITE_PORT REVERB_SERVER_PORT

# Start all services using concurrently
echo -e "${GREEN}Starting Laravel server, Vite, queue worker, and log watcher...${NC}"

composer run dev &

# Wait a moment for services to start
sleep 3

# Check if Caddy is already running
if ! pgrep -x "caddy" > /dev/null; then
    echo -e "${GREEN}Starting Caddy reverse proxy...${NC}"
    caddy start --config Caddyfile
else
    echo -e "${YELLOW}Caddy is already running. Reloading configuration...${NC}"
    caddy reload --config Caddyfile
fi

echo -e "${GREEN}✓ Development environment is ready!${NC}"
echo -e "${GREEN}Access your application at: https://dev.lara.blb${NC}"
echo ""
echo "Services running:"
echo "  - Laravel: http://127.0.0.1:8000"
echo "  - Vite: http://127.0.0.1:5173"
echo "  - Caddy: https://dev.lara.blb"
echo ""
echo "Press Ctrl+C to stop all services"
```

#### 2.2 Auto-Start Script for Staging

**Location:** `scripts/start-staging.sh`

Similar to development but:
- Uses different ports (8001 for Laravel, 5174 for Vite)
- Sets `APP_ENV=staging`
- Uses staging Caddy configuration
- Points to staging repository path

#### 2.3 Stop Services Script

**Location:** `scripts/stop-services.sh`

```bash
#!/bin/bash

# SPDX-License-Identifier: AGPL-3.0-only
# Copyright (c) 2025 Ng Kiat Siong

set -e

ENVIRONMENT=${1:-dev}

echo "Stopping $ENVIRONMENT environment services..."

# Kill processes by port
case $ENVIRONMENT in
    dev)
        lsof -ti:8000 | xargs kill -9 2>/dev/null || true
        lsof -ti:5173 | xargs kill -9 2>/dev/null || true
        ;;
    staging)
        lsof -ti:8001 | xargs kill -9 2>/dev/null || true
        lsof -ti:5174 | xargs kill -9 2>/dev/null || true
        ;;
esac

# Kill concurrently processes
pkill -f "concurrently" || true

echo "Services stopped."
```

### Phase 3: Environment Configuration

#### 3.1 Development Environment (.env)

```env
APP_NAME="BLB Development"
APP_ENV=local
APP_DEBUG=true
APP_URL=https://dev.lara.blb

# ... other configurations
```

#### 3.2 Staging Environment (.env)

```env
APP_NAME="BLB Staging"
APP_ENV=staging
APP_DEBUG=true
APP_URL=https://stage.lara.blb

# ... other configurations
```

### Phase 4: Hot Reloading Setup

> **See also:** [Understanding Vite's Roles](../tutorials/vite-roles.md) for detailed explanation of how Vite enables hot reloading.

#### 4.1 How Hot Reloading Works

1. **CSS/JS Changes** (via Vite HMR):
   - File changes trigger Vite's Hot Module Replacement system
   - Vite recompiles only the changed files instantly
   - Vite sends update notification via WebSocket connection
   - Browser receives signal and updates changed modules without full page refresh
   - Application state is preserved (forms, scroll position, etc.)

2. **Blade/PHP Changes** (via Laravel Vite Plugin):
   - The `refresh` option in `vite.config.js` watches Blade template directories
   - On change, Vite sends a refresh signal to browser via WebSocket
   - Browser performs a full page reload to show updated Blade output
   - Full reload is necessary because PHP/Blade changes require server-side rendering

3. **Caddy Proxy Role**:
   - Caddy transparently proxies WebSocket connections from HTTPS to Vite's HTTP server
   - HTTPS WebSocket (wss://dev.lara.blb) automatically translates to HTTP WebSocket (ws://127.0.0.1:5173)
   - No additional configuration needed - Caddy handles WebSocket upgrade automatically
   - Proper headers are forwarded so Vite can identify the connection

#### 4.2 Composer Script Enhancement

Update `composer.json` so the dev workflow consumes runtime-managed ports instead of hardcoded local/staging values:

```json
{
    "scripts": {
        "dev": [
            "Composer\\Config::disableProcessTimeout",
            "bunx concurrently -c \"#93c5fd,#c4b5fd,#86efac,#fdba74\" \"php artisan serve --port=${APP_PORT:-8000}\" \"php artisan queue:listen --tries=1\" \"php artisan reverb:start\" \"bun run dev\" --names=server,queue,reverb,vite"
        ]
    }
}
```

In this model, `start-app.sh` owns port selection and exports the resolved values before `composer run dev` starts the processes.

### Phase 5: Auto-Start on System Boot

#### 5.1 Systemd Service (Linux)

**Location:** `/etc/systemd/system/caddy-blb-dev.service`

```ini
[Unit]
Description=Caddy Development Environment for BLB
After=network.target

[Service]
Type=simple
User=kiat
WorkingDirectory=/home/kiat/repo/laravel/blb
ExecStart=/home/kiat/repo/laravel/blb/scripts/start-dev.sh
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

**Enable auto-start:**
```bash
sudo systemctl enable caddy-blb-dev.service
sudo systemctl start caddy-blb-dev.service
```

#### 5.2 LaunchAgent (macOS)

**Location:** `~/Library/LaunchAgents/com.blb.dev.plist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.blb.dev</string>
    <key>ProgramArguments</key>
    <array>
        <string>/home/kiat/repo/laravel/blb/scripts/start-dev.sh</string>
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>WorkingDirectory</key>
    <string>/home/kiat/repo/laravel/blb</string>
</dict>
</plist>
```

#### 5.3 Windows Service (Optional)

Use NSSM (Non-Sucking Service Manager) or similar tool to create a Windows service.

### Phase 6: Multi-Repository Support

#### 6.1 Directory Structure for Multi-Repo

```
/home/kiat/
├── repo/
│   ├── laravel/
│   │   └── blb/              # Development repo
│   │       ├── Caddyfile
│   │       └── scripts/
│   └── laravel-staging/
│       └── blb/              # Staging repo
│           ├── Caddyfile
│           └── scripts/
```

#### 6.2 Staging Caddyfile

Staging repository has its own `Caddyfile` that:
- Uses `stage.lara.blb` domain
- Points to different ports (8001, 5174)
- Uses staging-specific log files
- Can be in a completely different directory

#### 6.3 Unified Caddy Configuration (Alternative)

Alternatively, use a single Caddyfile that references both repos:

```caddy
# Main Caddyfile at ~/repo/laravel/blb/Caddyfile

import /home/kiat/repo/laravel/blb/Caddyfile.dev
import /home/kiat/repo/laravel-staging/blb/Caddyfile.staging
```

### Phase 7: Production Configuration

#### 7.1 Production Caddyfile

**Location:** `config/caddy/production.conf`

```caddy
your-production-domain.com {
    # Real SSL certificates (Let's Encrypt)
    tls your-email@example.com

    # Security headers
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        X-XSS-Protection "1; mode=block"
        Referrer-Policy "strict-origin-when-cross-origin"
    }

    # Gzip compression
    encode gzip

    # Serve static assets directly from public directory
    handle /build/* {
        root * public
        file_server
        header Cache-Control "public, max-age=31536000, immutable"
    }

    # Proxy to Laravel (PHP-FPM or server)
    reverse_proxy unix//var/run/php/php8.2-fpm.sock {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}
```

#### 7.2 Production Deployment Notes

- Use compiled assets (`npm run build`)
- No Vite dev server in production
- Use PHP-FPM instead of `php artisan serve`
- Configure proper SSL certificates
- Set up proper caching headers

---

## User Workflow

### Initial Setup (One-Time)

1. **Install Caddy:**
   ```bash
   # Linux
   sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
   sudo apt update
   sudo apt install caddy
   ```

2. **Configure /etc/hosts:**
   ```bash
   echo "127.0.0.1 dev.lara.blb" | sudo tee -a /etc/hosts
   echo "127.0.0.1 stage.lara.blb" | sudo tee -a /etc/hosts
   ```

3. **Install project dependencies:**
   ```bash
   composer install
   npm install
   ```

4. **Make scripts executable:**
   ```bash
   chmod +x scripts/*.sh
   ```

### Daily Development Workflow

1. **Start development environment:**
   ```bash
   ./scripts/start-dev.sh
   ```

2. **Access application:**
   - Open browser to `https://dev.lara.blb`
   - Files auto-reload on changes

3. **Stop services:**
   ```bash
   ./scripts/stop-services.sh dev
   ```

### For Auto-Start Users

If auto-start is configured:
- Simply ensure Caddy service is running
- Services start automatically on system boot
- Access `https://dev.lara.blb` anytime

---

## Technical Details

### Port Allocation

| Service | Development | Staging | Production |
|---------|------------|---------|------------|
| Laravel | 8000 | 8001 | PHP-FPM socket |
| Vite | 5173 | 5174 | N/A (compiled) |
| Queue | N/A | N/A | Separate worker |
| Logs | N/A | N/A | File/remote |

### SSL Certificate Management

- **Development/Staging**: Caddy uses `tls internal` for automatic self-signed certificates
- **Production**: Caddy automatically provisions Let's Encrypt certificates
- Certificates are stored in `.caddy/certs/` (development) or Caddy's data directory (production)

### Hot Reloading Mechanism

1. **File Watcher**: Vite watches `resources/core/css/`, `resources/core/js/`, `resources/core/views/`
2. **Change Detection**: File system events trigger Vite rebuild
3. **WebSocket**: Vite HMR uses WebSocket (wss://dev.lara.blb) for notifications
4. **Browser Update**: Browser receives update signal and reloads changed modules/pages

### Caddy Reverse Proxy Benefits

- **Automatic HTTPS**: No manual certificate management
- **WebSocket Support**: Transparent WebSocket proxying for Vite HMR
- **Header Management**: Properly forwards headers for Laravel
- **Logging**: Built-in access logging
- **Performance**: Efficient reverse proxy implementation

---

## Troubleshooting

### Services Not Starting

**Check if ports are in use:**
```bash
lsof -i :8000
lsof -i :5173
```

**Check Caddy status:**
```bash
caddy status
caddy reload --config Caddyfile
```

### Hot Reload Not Working

1. **Check Vite HMR configuration** in `vite.config.js`
2. **Verify WebSocket connection** in browser DevTools (Network tab)
3. **Check Caddy is proxying WebSocket** properly
4. **Verify file watcher permissions** on project directory

### SSL Certificate Issues

**Development:**
- Caddy should automatically create certificates
- Check `.caddy/certs/` directory exists
- Ensure `.blb` domain is in `/etc/hosts`

**Production:**
- Ensure domain DNS points to server
- Check firewall allows port 80 and 443
- Verify email in Caddyfile for Let's Encrypt

---

## Security Considerations

### Development/Staging

- Self-signed certificates (acceptable for local development)
- Services bound to localhost (127.0.0.1) only
- Debug mode enabled (for development)

### Production

- Real SSL certificates (Let's Encrypt)
- Security headers configured
- Debug mode disabled
- Proper authentication and authorization
- Rate limiting configured
- Regular security updates

---

## Future Enhancements

1. **Docker Compose Integration**: Containerize entire development environment
2. **Database Per Environment**: Separate databases for dev/staging
3. **Redis Per Environment**: Separate Redis instances
4. **Shared Development Database**: Optional shared database for team collaboration
5. **Database Seeding**: Auto-seed development database on start
6. **Test Data Generation**: Generate realistic test data automatically
7. **Performance Monitoring**: Built-in performance metrics
8. **Error Tracking**: Integration with error tracking services
9. **Multiple Projects**: Support multiple projects with different domains

---

## Migration Path

### Step 1: Install and Configure Caddy
- Install Caddy
- Create Caddyfile
- Add domains to /etc/hosts

### Step 2: Update Vite Configuration
- Modify `vite.config.js` for proper HMR
- Update port configurations

### Step 3: Create Startup Scripts
- Create `start-dev.sh`
- Create `stop-services.sh`
- Make scripts executable

### Step 4: Test Setup
- Start services
- Verify https://dev.lara.blb works
- Test hot reloading

### Step 5: Set Up Auto-Start (Optional)
- Configure systemd/LaunchAgent
- Enable auto-start on boot

### Step 6: Configure Staging (Optional)
- Set up staging repository
- Configure staging Caddyfile
- Test staging environment

---

## Conclusion

This architecture provides a simplified, professional development experience:

- **Zero Configuration**: Just run Caddy, everything else auto-starts
- **Friendly URLs**: No need to remember IPs and ports
- **Hot Reloading**: Automatic refresh on file changes
- **Multi-Environment**: Separate dev/staging repos work seamlessly
- **Production Ready**: Same architecture scales to production

The setup reduces technical barriers for developers while maintaining the flexibility and power needed for modern web development.

---

**Document Status:** Implementation Plan
**Next Steps:**
1. Create Caddyfile templates
2. Implement startup scripts
3. Test hot reloading
4. Document setup process
5. Create migration guide from current setup
