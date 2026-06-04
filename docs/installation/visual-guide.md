# Visual Installation Guide

Visual overview of Belimbing installation with decision trees and flow diagrams.

## Quick Decision: Which Method?

```
                    ┌─────────────────┐
                    │  Start Install  │
                    └────────┬────────┘
                             │
                             ▼
              ┌──────────────────────────┐
              │  What's your use case?   │
              └──────────────┬───────────┘
                             │
         ┌───────────────────┼───────────────────┐
         │                   │                   │
         ▼                   ▼                   ▼
   ┌───────────┐      ┌────────────┐      ┌───────────┐
   │Production │      │Development │      │ Quick Try │
   │  Server   │      │  Machine   │      │   / Demo  │
   └─────┬─────┘      └──────┬─────┘      └─────┬─────┘
         │                   │                  │
         ▼                   ▼                  ▼
   ┌───────────┐      ┌────────────┐      ┌───────────┐
   │  Native   │      │   Docker   │      │  Docker   │
   │  Install  │      │   Install  │      │  Install  │
   └───────────┘      └────────────┘      └───────────┘
```

## Method Comparison

| Aspect | Native | Docker |
|--------|--------|--------|
| **Command** | `./scripts/setup.sh local` | `./scripts/start-docker.sh local` |
| **Best For** | Production | Development |
| **Performance** | Better | Good |
| **Cleanup** | Manual | `docker compose down` |
| **Database** | System PostgreSQL | Container PostgreSQL |

## Installation Flow

### Native Installation

```
┌──────────────────────┐
│  ./scripts/setup.sh  │
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Check Requirements  │ ── PHP, Git, etc.
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Install PostgreSQL  │ ── System service
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Install Redis       │ ── System service
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Configure Laravel   │ ── .env, keys
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Run Migrations      │ ── Database tables
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  Create Admin        │ ── First user
└──────────┬───────────┘
           │
           ▼
┌──────────────────────┐
│  ✓ Ready!            │
│  ./scripts/start-app │
└──────────────────────┘
```

### Docker Installation

```
┌────────────────────────────┐
│  ./scripts/start-docker.sh │
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  Check/Install Docker      │
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  Start Containers          │ ── PostgreSQL, Redis, App
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  Wait for Health           │ ── All services ready
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  Run Migrations            │ ── Inside container
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  Create Admin              │ ── Interactive prompt
└──────────────┬─────────────┘
               │
               ▼
┌────────────────────────────┐
│  ✓ Ready!                  │
│  https://local.blb.lara    │
└────────────────────────────┘
```

## Docker Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        Docker Network                       │
│                                                             │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────┐ │
│  │ postgres │  │  redis   │  │   app    │  │    caddy     │ │
│  │   :5432  │  │   :6379  │  │   :8000  │  │  :80 / :443  │ │
│  └──────────┘  └──────────┘  └──────────┘  └──────────────┘ │
│       │              │             │              │         │
│       └──────────────┴─────────────┴──────────────┘         │
│                                                             │
│  Dev Profile: + vite (:5173)                                │
│  Prod Profile: + queue (worker)                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
                     ┌────────────────┐
                     │   localhost    │
                     │   :80 / :443   │
                     └────────────────┘
```

## Verification Checklist

After installation, verify:

| Check | Command | Expected |
|-------|---------|----------|
| Web loads | Visit `https://local.blb.lara` | Login page |
| Health | `curl localhost:8000/health` | `{"status":"ok"}` |
| Database | `php artisan tinker` → `DB::connection()->getPdo()` | No error |
| Redis | `redis-cli ping` | `PONG` |

## Common Issues Quick Reference

| Problem | Fix |
|---------|-----|
| Port 8000 in use | `./scripts/stop-app.sh` or stop other service |
| Port 5432 in use | Stop system PostgreSQL: `sudo systemctl stop postgresql` |
| Docker won't start | Check Docker Desktop is running (WSL2) |
| Permission denied | `sudo chown -R $USER:$USER storage` |

## Next Steps

After installation:

1. **Access:** https://local.blb.lara
2. **Login:** Use admin credentials created during setup
3. **Explore:** Check [Architecture Docs](../architecture/)

---

For detailed commands, see [Quick Start Guide](quickstart.md).
