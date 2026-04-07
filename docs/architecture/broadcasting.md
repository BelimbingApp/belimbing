# Broadcasting Architecture

**Document Type:** Architecture Specification
**Purpose:** Define the real-time broadcasting infrastructure using Laravel Reverb and Echo.
**Last Updated:** 2026-02-12

## Overview

Belimbing uses **Laravel Reverb** (WebSocket server) and **Laravel Echo** (JavaScript client) for real-time broadcasting. This enables the server to push live updates to the browser without polling.

Currently used for:
-   Postcode import progress tracking (Geonames module).

Planned for:
-   AI chat streaming.
-   Live notifications and activity feeds.

---

## 1. Infrastructure

| Component | Technology | Location |
| :--- | :--- | :--- |
| **WebSocket Server** | Laravel Reverb (`laravel/reverb`) | Runs on port 8080 (`ws://localhost:8080`) |
| **JS Client** | Laravel Echo + `pusher-js` | `resources/core/js/echo.js` |
| **Broadcasting Driver** | `reverb` | Configured via `BROADCAST_CONNECTION=reverb` in `.env` |

Reverb implements the Pusher protocol, so Echo connects using `pusher-js` under the hood. No external service (Pusher, Ably) is required — everything runs locally.

---

## 2. Development Setup

Reverb, Vite, and the queue worker all start together via a single command:

```bash
# Start everything (Reverb + Vite + Queue Worker)
bun run dev:all
# Or equivalently:
composer run dev
```

Both use `concurrently` to run the processes in parallel. You can also start them individually:

```bash
php artisan reverb:start          # WebSocket server
php artisan queue:work            # Queue agent (required for ShouldBroadcast events)
bun run dev                       # Vite dev server
```

**Important:** The queue worker is required for events that implement `ShouldBroadcast`. Without it, queued broadcast events will not be delivered.

---

## 3. Creating Broadcast Events

### Choosing the Broadcast Interface

| Interface | Use When | Delivery |
| :--- | :--- | :--- |
| `ShouldBroadcastNow` | Event is dispatched from a **queued job** | Immediate (bypasses queue) |
| `ShouldBroadcast` | Event is dispatched from an **HTTP request** | Queued (requires `queue:work`) |

**Rationale:** When a queued job dispatches an event, the job is already running on a agent. Using `ShouldBroadcastNow` avoids double-queuing. For HTTP requests, `ShouldBroadcast` offloads the broadcast to the queue so the response is not delayed.

### Directory Convention

Place broadcast events in the module's `Events/` directory:

```text
app/Modules/Core/Geonames/
├── Events/
│   └── PostcodeImportProgress.php
├── Jobs/
│   └── ImportPostcodes.php
└── ...
```

### Example Event

```php
namespace App\Modules\Core\Geonames\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class PostcodeImportProgress implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $processed,
        public int $total,
        public string $country,
    ) {}

    /**
     * Channel(s) the event broadcasts on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('imports'),
        ];
    }
}
```

All public properties are automatically included in the broadcast payload.

---

## 4. Listening on the Frontend

### Echo Initialization

Echo is initialized in `resources/core/js/echo.js` and attached to `window.Echo`. It is available globally in any Blade/Livewire view.

### Listening with Alpine.js

Use Alpine's `x-data` + `x-init` in Livewire components to subscribe to channels:

```html
<div
    x-data="{ processed: 0, total: 0, country: '' }"
    x-init="
        Echo.channel('imports')
            .listen('.App\\Modules\\Core\\Geonames\\Events\\PostcodeImportProgress', (e) => {
                processed = e.processed;
                total = e.total;
                country = e.country;
            })
    "
>
    <p x-show="total > 0" x-text="`${country}: ${processed} / ${total}`"></p>
</div>
```

**Event name format:** Prefix with a dot (`.`) followed by the fully qualified class name, using `\\` as the namespace separator.

### Channel Types

| Channel Class | Purpose | Authorization |
| :--- | :--- | :--- |
| `Channel` | Public — any connected client can listen | None |
| `PrivateChannel` | Private — requires authentication | Define in `routes/channels.php` |

```php
// Public channel
new Channel('imports');

// Private channel (requires auth callback)
new PrivateChannel('user.' . $this->userId);
```

For private channels, register the authorization callback:

```php
// routes/channels.php
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

---

## 5. Environment Variables

Add these to your `.env` file. The `VITE_REVERB_*` variables expose connection details to the frontend via Vite:

- Local `./scripts/start-app.sh` runs do not need a pinned `REVERB_SERVER_PORT`; the script auto-selects a free internal listener port, then exports the public Reverb endpoint on `FRONTEND_DOMAIN` so both Laravel and Echo talk to Caddy's proxied `https://` / `wss://` host.
- Manual, staging, or production deployments should set the Reverb listener / public connection values explicitly for their process manager or container runtime.

```dotenv
BROADCAST_CONNECTION=reverb

FRONTEND_DOMAIN=local.blb.lara

REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST="${FRONTEND_DOMAIN}"
REVERB_PORT=443
# Optional: Reverb server listener override when not using start-app auto-assignment
# REVERB_SERVER_PORT=8080
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

## 6. Related Documentation

When Reverb is reverse-proxied, the web server must forward both `/app` (WebSocket upgrades) and `/apps` (broadcast API requests) to the internal Reverb listener.

-   `docs/architecture/database.md`: Database architecture and module conventions.
-   `docs/architecture/file-structure.md`: Full project directory layout.
