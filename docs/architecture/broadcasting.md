# Broadcasting Architecture

**Document Type:** Architecture Specification
**Scope:** Real-time communication patterns
**Last Updated:** 2026-04-23

## Overview

BLB does not use Laravel Reverb, Laravel Echo, or WebSocket broadcasting.

Real-time communication uses simpler, fit-for-purpose patterns:

- **AI chat streaming:** Direct Server-Sent Events (SSE) via HTTP/2
- **Import progress:** Livewire's built-in `wire:loading` during synchronous operations
- **Notifications:** Database-backed notifications via Laravel's notification system

The `BROADCAST_CONNECTION` defaults to `null`. The broadcasting config retains the standard Laravel connection definitions (Pusher, Ably, Redis, log) for reference but none are active.

## Future Opportunity: Mercure

FrankenPHP bundles Mercure, an SSE-based pub/sub hub with built-in authorization and private topics. If BLB's real-time needs grow beyond what polling and direct SSE cover, Mercure is the natural next step — no additional process required.
