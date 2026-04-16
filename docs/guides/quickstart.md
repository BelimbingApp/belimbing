# Quick Start Guide

Belimbing is built on [Laravel](https://laravel.com/) and [Livewire](https://laravel-livewire.com/), this guide uses bash scripts to install the dependencies and configure the environment. There are two methods to setup the environment: [Native](#native-installation) and [Docker](#docker-installation).

| Method | Skill Level | Commands |
|--------|-------------|---------|
| **Native** | Basic Linux | `./scripts/setup.sh`, `./scripts/start-app.sh`, `./scripts/stop-app.sh` |
| **Docker** | **Intermediate** (Requires Docker knowledge) | `./scripts/start-docker.sh`, `./scripts/stop-docker.sh` |

> **Recommendation:** If you are unsure or have never used Docker before, use the **Native** method. It is easier to troubleshoot on a standard Linux server.

> **Warning:** Use only ONE method. Running both causes port conflicts.

> **Note:** Docker and Native installations use separate `.env` files:
> - **Native**: Uses root `.env` file
> - **Docker**: Uses `./docker/.env` file
> This allows both methods to coexist with different configurations.

## Prerequisites

Belimbing requires Linux; on Windows, it runs in [WSL2](https://learn.microsoft.com/en-us/windows/wsl/about). This guide assumes Debian-based Linux, with the `apt-get` package manager. Replace `apt-get` with your package manager of choice.

Belimbing requires [Git](https://git-scm.com/), which is pre-installed on Linux, WSL2, and most MacOS systems. For information on how to install Git, see this [guide](https://github.com/git-guides/install-git).

### Clone the Repository
Get the Belimbing codebase from GitHub by cloning the repository to your local machine. To clone it on your home directory, launch a terminal, and run the following commands:

```bash
cd ~
git clone https://github.com/BelimbingApp/belimbing.git belimbing
cd belimbing # go to the project root
```

## Native Installation

The setup script will install the dependencies and configure the environment. To setup a local development environment, run the following command at the project root:

```bash
cd ~/belimbing # go to the project root
./scripts/setup.sh
```

The script will install or upgrade PHP, Composer, PostgreSQL, Redis, FrankenPHP, and help configure everything. Native setup defaults to shared ingress and still lets you choose:

- `shared` — recommended default; system Caddy owns `:80` and `:443`, while BLB runs FrankenPHP on a pinned local app port
- `direct` — single-instance fallback where BLB serves HTTPS itself on `:443` when no system Caddy is active

When `shared` mode is selected, setup validates or installs system Caddy, generates the BLB site block, and reloads the daemon when possible. You can stop anytime by pressing `Ctrl+C`. The script can be run multiple times and is intended to be idempotent on already-provisioned machines.

For _staging_ and _production_ environments, run `./scripts/setup.sh staging` or `./scripts/setup.sh production`.

### Hosts File Configuration

The setup script will automatically configure the hosts file on the Linux system:

**Linux (Native installation):**
```bash
# Add to /etc/hosts
127.0.0.1 local.blb.lara local.api.blb.lara
```

For WSL2 users, you need to configure the hosts file to access the app from the Windows browser. You need to use the **WSL2 IP address** instead of `127.0.0.1`.

1. **Find your WSL2 IP address:**
   ```bash
   # In WSL2 terminal
   ip addr show eth0 | grep "inet " | awk '{print $2}' | cut -d/ -f1
   ```

2. **Add to Windows hosts file** (`C:\Windows\System32\drivers\etc\hosts`):
   ```
   172.25.114.176 local.blb.lara local.api.blb.lara
   ```
   *(Replace `172.25.114.176` with your actual WSL2 IP address)*

3. **Edit as Administrator:**
   - Open Notepad as Administrator (Win+R → `notepad` → Ctrl+Shift+Enter)
   - Or use PowerShell as Administrator:
     ```powershell
     Add-Content -Path "C:\Windows\System32\drivers\etc\hosts" -Value "172.25.114.176 local.blb.lara local.api.blb.lara"
     ```

> **Why?** Windows `127.0.0.1` points to Windows localhost, not WSL2. Using the WSL2 IP allows Windows browsers to reach services running in WSL2.

### Start and Stop the App

Once the setup is complete, you can start the app using the following command:

```bash
cd ~/belimbing # go to the project root
./scripts/start-app.sh
```

`./scripts/start-app.sh` automatically chooses free internal ports for Laravel, Vite, and Reverb when they are not pinned in `.env`, so multiple local BLB instances can run side-by-side without manual port edits. In `shared` ingress mode, keep `APP_PORT` pinned in `.env` so the generated system Caddy config always points to a stable upstream.

In `direct` mode, the app is usually available at `https://local.blb.lara`. In `shared` mode, the public URL remains the same, but system Caddy is the process serving `:443` and proxying to BLB's local FrankenPHP listener. To stop the app, press `Ctrl+C` in the terminal. The script will automatically stop the app when you exit the terminal. You can also stop the app manually using the following command:

**Stop:**
```bash
./scripts/stop-app.sh
```

## SSL Certificate Trust

When accessing custom domains like `https://local.blb.lara` from a browser, you'll see a certificate warning because Caddy uses self-signed certificates for development. To trust the certificate:

1. **Navigate to the certificate in Windows Explorer:**
   - Open Windows Explorer
   - Navigate to: `<project_root>\storage\app\ssl`

2. **Double-click `caddy-root-ca.crt`**

3. **Install the certificate:**
   - Click "Install Certificate" → "Local Machine" → Next
   - Select "Place all certificates in the following store"
   - Click "Browse" → Select "Trusted Root Certification Authorities" → OK → Next → Finish

4. **Restart your browser**

> **Alternative:** You can also accept the browser warning each time (safe for self-signed development certificates).

## Docker Installation

```bash
git clone <repository-url> belimbing
cd belimbing
./scripts/start-docker.sh
```

The script handles Docker installation, service startup, migrations, and admin creation.

**Stop:**
```bash
./scripts/stop-docker.sh
```

**Manual commands** (from `docker/` directory):
```bash
# Development
docker compose --profile dev up -d

# Production
docker compose --profile prod up -d

# Logs
docker compose --profile dev logs -f

# Stop
docker compose --profile dev down

# Run artisan
docker compose --profile dev exec app php artisan <command>
```

### Access the App

- **Web:** https://local.blb.lara
- **API:** https://local.blb.lara/api

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Port in use | Stop conflicting service or change port in `.env` (root `.env` for native, `./docker/.env` for Docker) |
| Database error | Check PostgreSQL: `systemctl status postgresql` |
| Docker error | Check Docker Desktop is running |
| Permission error | `sudo chown -R $USER:$USER storage` |
| Docker Permission Denied | Common issue. Ensure your user ID matches or run `sudo chown -R 1000:1000 storage` inside the container context. |
| Wrong config in Docker | Ensure `./docker/.env` exists and has Docker-specific values (DB_HOST=postgres, REDIS_HOST=redis) |
| 502 Bad Gateway from Windows browser (WSL2) | Use WSL2 IP address in Windows hosts file instead of `127.0.0.1`. See [Hosts File Configuration](#hosts-file-configuration) above. |

## Next Steps

- [Visual Guide](visual-guide.md) - Installation diagrams
- [Architecture](../architecture/) - System design
- [Troubleshooting](troubleshooting.md) - Common issues
